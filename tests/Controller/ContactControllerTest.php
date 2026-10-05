<?php

namespace App\Tests\Controller;

use App\Controller\ContactController;
use App\Service\PageConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ContactControllerTest extends WebTestCase
{
    private ?Connection $connection = null;
    private ?string $testDirectory = null;

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->connection?->close();
        if ($this->testDirectory !== null) {
            (new Filesystem())->remove($this->testDirectory);
        }
    }

    public function testContactPagePreservesTheProvidedContentAndSharedLayout(): void
    {
        $client = $this->contactClient(false);
        $crawler = $client->request('GET', '/contact');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '.contact-page');
        self::assertSelectorTextContains('.contact-page h1', 'Contact Operations');
        self::assertSelectorTextContains('.contact-page', 'Global Operations');
        self::assertSelectorTextContains('.contact-page', 'dispatch@apexmarine.com.ph');
        self::assertSelectorCount(4, '.contact-hub');
        self::assertSelectorCount(8, '.contact-hub-entry');
        foreach (['Hinoba-an', 'Singapore', 'Shanghai', 'Rotterdam', 'Dubai', 'Cape Town', 'Houston', 'Panama City'] as $office) {
            self::assertSelectorTextContains('.contact-page', $office);
        }
        self::assertSelectorCount(1, '.topbar');
        self::assertSelectorCount(1, '.sidebar');
        self::assertSelectorCount(1, '.dashboard-footer');
        self::assertSelectorTextContains('.sidebar nav a[aria-current="page"]', 'Contact');
        self::assertSelectorCount(0, 'a[href*=".php"]');

        self::assertSame('/contact', $crawler->filter('form.contact-form')->attr('action'));
        self::assertSame('post', strtolower($crawler->filter('form.contact-form')->attr('method')));
        self::assertSame('multipart/form-data', $crawler->filter('form.contact-form')->attr('enctype'));
        foreach (['_token', 'vessel_name', 'imo_number', 'vessel_type', 'company', 'contact_person', 'country_code', 'phone_number', 'eta_date', 'urgent', 'location', 'service_type', 'attachment', 'description'] as $field) {
            self::assertSelectorCount(1, 'form.contact-form [name="' . $field . '"]');
        }
        self::assertSelectorExists('.contact-clearance');
        self::assertSelectorCount(0, '.contact-form button[type="submit"]:not([disabled])');
        $locations = $crawler->filter('select[name="location"] option')->each(
            static fn ($option): string => $option->attr('value')
        );
        self::assertSame(['1', '2', '3', '4', '5', '6', '7'], array_values(array_filter(
            $locations, static fn (string $value): bool => $value !== ''
        )));
        self::assertSelectorExists('select[name="country_code"] option[value="Sat"]');
        self::assertSelectorExists('select[name="vessel_type"] option[value="Oil/Chemical Tanker"]');
        self::assertSelectorExists('select[name="service_type"] option[value="General Consultation"]');

        $route = static::getContainer()->get('router')->match('/contact');
        self::assertSame('app_contact', $route['_route']);
        self::assertSame(ContactController::class . '::index', $route['_controller']);
        $this->assertNoRequestsOrUploads();
    }

    public function testGuestCannotSubmitOrStoreAnAttachment(): void
    {
        $client = $this->contactClient(false);
        $client->request('POST', '/contact', $this->validValues(), ['attachment' => $this->pdfUpload()]);

        self::assertResponseStatusCodeSame(403);
        self::assertSelectorTextContains('.contact-alert-error', 'Clearance');
        $this->assertNoRequestsOrUploads();
    }

    #[DataProvider('invalidSessionIdentities')]
    public function testInvalidSessionIdentityCannotSubmit(mixed $identity): void
    {
        $client = $this->contactClient(false);
        $this->setSessionIdentity($client, $identity);
        $client->request('POST', '/contact', $this->validValues());

        self::assertResponseStatusCodeSame(403);
        $this->assertNoRequestsOrUploads();
    }

    public static function invalidSessionIdentities(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'array' => [['7']];
        yield 'boolean' => [true];
        yield 'float' => [7.0];
    }

    public function testAuthenticatedSubmissionRequiresAValidCsrfToken(): void
    {
        $client = $this->contactClient();
        $this->csrfToken($client);
        foreach ([null, 'invalid-token', ['unexpected-array']] as $token) {
            $values = $this->validValues();
            if ($token !== null) {
                $values['_token'] = $token;
            }
            $client->request('POST', '/contact', $values);
            self::assertResponseStatusCodeSame(403);
            self::assertSelectorExists('.contact-alert-error');
            $this->assertNoRequestsOrUploads();
        }
    }

    public function testSuccessfulDispatchUsesSessionIdentityAndRedirectsBeforeRefresh(): void
    {
        $client = $this->contactClient();
        $values = $this->validValues();
        $values['_token'] = $this->csrfToken($client);
        $values['client_id'] = '999';
        $values['vessel_name'] = '  <script>alert("vessel")</script>  ';
        $values['urgent'] = '1';
        $client->request('POST', '/contact', $values);

        self::assertResponseRedirects('/contact', 303);
        $row = $this->connection->fetchAssociative('SELECT * FROM dispatch_requests');
        self::assertIsArray($row);
        self::assertSame(7, (int) $row['client_id']);
        self::assertSame(trim($values['vessel_name']), $row['vessel_name']);
        self::assertSame('+63 917 000 1234', $row['contact_phone']);
        self::assertSame(1, (int) $row['is_urgent']);
        self::assertSame(1, (int) $row['location_id']);
        self::assertSame('pending', $row['status']);
        self::assertSame(1, (int) $row['is_active']);
        self::assertNull($row['attachment_path']);

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.contact-alert-success', trim($values['vessel_name']));
        self::assertStringNotContainsString('<script>alert("vessel")</script>', $client->getResponse()->getContent());
        self::assertSelectorExists('.contact-form button[type="submit"]:not([disabled])');
        $client->request('GET', '/contact');
        self::assertResponseIsSuccessful();
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM dispatch_requests'));
    }

    public function testOptionalCompanyAndNonUrgentSatelliteRequestAreSupported(): void
    {
        $client = $this->contactClient();
        $values = $this->validValues();
        $values['_token'] = $this->csrfToken($client);
        unset($values['company']);
        $values['country_code'] = 'Sat';
        $values['phone_number'] = '870 773 123456';
        $client->request('POST', '/contact', $values);

        self::assertResponseRedirects('/contact', 303);
        $row = $this->connection->fetchAssociative('SELECT * FROM dispatch_requests');
        self::assertSame(0, (int) $row['is_urgent']);
        self::assertSame('Sat 870 773 123456', $row['contact_phone']);
        self::assertSame('', $row['company']);
        self::assertNull($row['attachment_path']);
    }

    #[DataProvider('invalidFields')]
    public function testInvalidFieldsAreRejectedWithoutLosingTheOtherInputs(array $changes): void
    {
        $client = $this->contactClient();
        $values = array_replace($this->validValues(), $changes);
        $values['_token'] = $this->csrfToken($client);
        $crawler = $client->request('POST', '/contact', $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.contact-alert-error');
        self::assertSame('MV Test Vessel', $crawler->filter('[name="vessel_name"]')->attr('value'));
        self::assertSame('Heavy-duty engine inspection.', $crawler->filter('textarea[name="description"]')->text());
        $this->assertNoRequestsOrUploads();
    }

    public static function invalidFields(): iterable
    {
        yield 'contact person missing' => [['contact_person' => '  ']];
        yield 'contact person array' => [['contact_person' => ['Captain']]];
        yield 'IMO format' => [['imo_number' => 'IMO123']];
        yield 'vessel type outside options' => [['vessel_type' => 'Unknown Type']];
        yield 'invalid calendar date' => [['eta_date' => '2030-02-30']];
        yield 'date containing NUL byte' => [['eta_date' => "2030-01\0-15"]];
        yield 'location outside options' => [['location' => '8']];
        yield 'location array' => [['location' => ['1']]];
        yield 'service outside options' => [['service_type' => 'Other']];
        yield 'country code outside options' => [['country_code' => '+999']];
        yield 'phone format' => [['phone_number' => 'not a number']];
        yield 'phone punctuation without digits' => [['phone_number' => '( ) -- ..']];
    }

    public function testExecutableContentRenamedAsAnImageIsRejected(): void
    {
        $client = $this->contactClient();
        $values = $this->validValues();
        $values['_token'] = $this->csrfToken($client);
        $path = $this->testDirectory . '/malicious.png';
        file_put_contents($path, '<?php echo "do not execute";');
        $file = new UploadedFile($path, 'damage.png', 'image/png', null, true);
        $client->request('POST', '/contact', $values, ['attachment' => $file]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.contact-alert-error');
        $this->assertNoRequestsOrUploads();
    }

    public function testAttachmentOverFiveMiBIsRejected(): void
    {
        $client = $this->contactClient();
        $values = $this->validValues();
        $values['_token'] = $this->csrfToken($client);
        $file = $this->pdfUpload(str_repeat('x', 5 * 1024 * 1024));
        $client->request('POST', '/contact', $values, ['attachment' => $file]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.contact-alert-error');
        $this->assertNoRequestsOrUploads();
    }

    public function testPdfAttachmentIsStoredPrivatelyWithARandomName(): void
    {
        $client = $this->contactClient();
        $values = $this->validValues();
        $values['_token'] = $this->csrfToken($client);
        $file = $this->pdfUpload();
        $expectedHash = hash_file('sha256', $file->getPathname());
        $client->request('POST', '/contact', $values, ['attachment' => $file]);

        self::assertResponseRedirects('/contact', 303);
        $path = $this->connection->fetchOne('SELECT attachment_path FROM dispatch_requests');
        self::assertIsString($path);
        self::assertStringStartsWith('var/uploads/dispatch/', $path);
        self::assertStringEndsWith('.pdf', $path);
        self::assertStringNotContainsString('engine-report', $path);
        self::assertStringNotContainsString('public/', $path);
        $storedFile = $this->uploadDirectory() . '/' . basename($path);
        self::assertFileExists($storedFile);
        self::assertSame($expectedHash, hash_file('sha256', $storedFile));
        self::assertCount(1, glob($this->uploadDirectory() . '/*') ?: []);
    }

    public function testDatabaseFailureShowsAGenericErrorAndRemovesTheUploadedFile(): void
    {
        $client = $this->contactClient();
        $values = $this->validValues();
        $values['_token'] = $this->csrfToken($client);
        $this->connection->executeStatement('DROP TABLE dispatch_requests');
        $client->request('POST', '/contact', $values, ['attachment' => $this->pdfUpload()]);

        self::assertResponseStatusCodeSame(503);
        self::assertSelectorExists('.contact-alert-error');
        self::assertStringNotContainsString('SQLSTATE', $client->getResponse()->getContent());
        self::assertStringNotContainsString('no such table', $client->getResponse()->getContent());
        self::assertSame([], glob($this->uploadDirectory() . '/*') ?: []);
    }

    private function contactClient(bool $authenticated = true): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->catchExceptions(false);
        $this->testDirectory = sys_get_temp_dir() . '/apex-contact-test-' . bin2hex(random_bytes(8));
        (new Filesystem())->mkdir($this->testDirectory);
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $this->testDirectory . '/requests.sqlite',
        ]);
        $this->connection->executeStatement('CREATE TABLE dispatch_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id INTEGER NOT NULL,
            vessel_name TEXT NOT NULL,
            imo_number TEXT NOT NULL,
            vessel_type TEXT NOT NULL,
            company TEXT NOT NULL,
            contact_person TEXT NOT NULL,
            contact_phone TEXT NOT NULL,
            eta_date TEXT NOT NULL,
            service_type TEXT NOT NULL,
            is_urgent INTEGER NOT NULL,
            location_id INTEGER NOT NULL,
            description TEXT NOT NULL,
            attachment_path TEXT,
            status TEXT NOT NULL,
            is_active INTEGER NOT NULL
        )');

        $container = static::getContainer();
        $container->set('doctrine.dbal.default_connection', $this->connection);
        $controller = new ContactController(
            $container->get(PageConfiguration::class),
            $this->connection,
            $this->uploadDirectory(),
            new NullLogger()
        );
        $controller->setContainer($container);
        $container->set(ContactController::class, $controller);
        if ($authenticated) {
            $this->setSessionIdentity($client, 7);
        }

        return $client;
    }

    private function setSessionIdentity(KernelBrowser $client, mixed $identity): void
    {
        $session = static::getContainer()->get('session.factory')->createSession();
        $session->set('user_id', $identity);
        $session->save();
        $client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));
    }

    private function csrfToken(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/contact');
        self::assertResponseIsSuccessful();

        return $crawler->filter('input[name="_token"]')->attr('value');
    }

    private function validValues(): array
    {
        return [
            'vessel_name' => 'MV Test Vessel',
            'imo_number' => '1234567',
            'vessel_type' => 'Bulk Carrier',
            'company' => 'Apex Test Company',
            'contact_person' => 'Captain Test',
            'country_code' => '+63',
            'phone_number' => '917 000 1234',
            'eta_date' => '2030-01-15',
            'location' => '1',
            'service_type' => 'Propulsion & Machinery',
            'description' => 'Heavy-duty engine inspection.',
        ];
    }

    private function pdfUpload(string $extraContent = ''): UploadedFile
    {
        $path = $this->testDirectory . '/fixture-' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($path, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n" . $extraContent);

        return new UploadedFile($path, 'engine-report.pdf', 'application/pdf', null, true);
    }

    private function uploadDirectory(): string
    {
        return $this->testDirectory . '/uploads';
    }

    private function assertNoRequestsOrUploads(): void
    {
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM dispatch_requests'));
        self::assertSame([], glob($this->uploadDirectory() . '/*') ?: []);
    }
}
