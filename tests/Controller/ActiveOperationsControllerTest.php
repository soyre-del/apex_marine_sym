<?php

namespace App\Tests\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Filesystem\Filesystem;

final class ActiveOperationsControllerTest extends WebTestCase
{
    private ?Connection $connection = null;
    private ?string $testDirectory = null;

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->connection?->close();
        $this->connection = null;
        static::ensureKernelShutdown();

        if ($this->testDirectory !== null) {
            (new Filesystem())->remove($this->testDirectory);
        }
    }

    public function testAdministratorCanViewActiveOperationsAndPostAnUpdate(): void
    {
        $client = $this->createAdminClient();
        $crawler = $client->request('GET', '/admin/active-operations');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Active Operations Tracker');
        self::assertSelectorTextContains('.active-ops-card', 'MV Test Vessel');
        self::assertSelectorTextContains('.active-ops-assigned', 'Test Engineer');

        $token = $crawler->filter('form.active-ops-form input[name="_token"]')->attr('value');
        $client->request('POST', '/admin/active-operations', [
            '_token' => $token,
            'action' => 'add_update',
            'request_id' => '11',
            'engineer_id' => '7',
            'milestone' => 'Engineer deployed',
            'detailed_message' => 'Engineer is en route to the vessel.',
            'new_status' => 'deployed',
        ]);

        self::assertResponseRedirects('/admin/active-operations');
        self::assertSame('deployed', $this->connection->fetchOne(
            'SELECT status FROM dispatch_requests WHERE request_id = 11'
        ));
        self::assertSame('en_route', $this->connection->fetchOne(
            'SELECT deployment_status FROM deployments WHERE request_id = 11 AND engineer_id = 7'
        ));
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM operation_updates WHERE request_id = 11 AND updater_id = 99'
        ));
        self::assertSame('on_duty', $this->connection->fetchOne(
            'SELECT current_status FROM engineer_profiles WHERE engineer_id = 7'
        ));

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.active-ops-alert-success', 'Timeline Updated for Request #11.');
    }

    #[DataProvider('terminalStatuses')]
    public function testTerminalStatusesCompleteDeploymentAndReleaseEngineer(string $status): void
    {
        $client = $this->createAdminClient();
        $crawler = $client->request('GET', '/admin/active-operations');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('form.active-ops-form input[name="_token"]')->attr('value');

        $client->request('POST', '/admin/active-operations', [
            '_token' => $token,
            'action' => 'add_update',
            'request_id' => '11',
            'engineer_id' => '7',
            'milestone' => 'Operation closed',
            'detailed_message' => 'The operation is complete.',
            'new_status' => $status,
        ]);

        self::assertResponseRedirects('/admin/active-operations');
        self::assertSame($status, $this->connection->fetchOne(
            'SELECT status FROM dispatch_requests WHERE request_id = 11'
        ));
        self::assertSame('completed', $this->connection->fetchOne(
            'SELECT deployment_status FROM deployments WHERE request_id = 11 AND engineer_id = 7'
        ));
        self::assertSame('available', $this->connection->fetchOne(
            'SELECT current_status FROM engineer_profiles WHERE engineer_id = 7'
        ));

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.active-ops-alert-success', 'Engineer freed.');
    }

    public function testGuestCannotViewActiveOperations(): void
    {
        $client = $this->createClientWithDatabase();
        $client->request('GET', '/admin/active-operations');

        self::assertResponseStatusCodeSame(403);
    }

    public static function terminalStatuses(): iterable
    {
        yield 'resolved' => ['resolved'];
        yield 'cancelled' => ['cancelled'];
    }

    private function createAdminClient(): KernelBrowser
    {
        $client = $this->createClientWithDatabase();
        $session = static::getContainer()->get('session.factory')->createSession();
        $session->set('user_id', 99);
        $session->set('role', 'admin');
        $session->save();
        $client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));

        return $client;
    }

    private function createClientWithDatabase(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->catchExceptions(false);

        $this->testDirectory = sys_get_temp_dir().'/apex-active-ops-test-'.bin2hex(random_bytes(8));
        (new Filesystem())->mkdir($this->testDirectory);
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $this->testDirectory.'/operations.sqlite',
        ]);
        $this->connection->executeStatement('CREATE TABLE dispatch_requests (
            request_id INTEGER PRIMARY KEY,
            vessel_name TEXT NOT NULL,
            status TEXT NOT NULL,
            is_urgent INTEGER NOT NULL,
            requested_at TEXT NOT NULL
        )');
        $this->connection->executeStatement('CREATE TABLE deployments (
            request_id INTEGER NOT NULL,
            engineer_id INTEGER NOT NULL,
            deployment_status TEXT NOT NULL
        )');
        $this->connection->executeStatement('CREATE TABLE users (
            user_id INTEGER PRIMARY KEY,
            full_name TEXT NOT NULL
        )');
        $this->connection->executeStatement('CREATE TABLE engineer_profiles (
            engineer_id INTEGER PRIMARY KEY,
            current_status TEXT NOT NULL
        )');
        $this->connection->executeStatement('CREATE TABLE operation_updates (
            request_id INTEGER NOT NULL,
            updater_id INTEGER NOT NULL,
            status_milestone TEXT NOT NULL,
            detailed_message TEXT NOT NULL
        )');

        $this->connection->insert('dispatch_requests', [
            'request_id' => 11,
            'vessel_name' => 'MV Test Vessel',
            'status' => 'pending',
            'is_urgent' => 1,
            'requested_at' => '2026-01-01 10:00:00',
        ]);
        $this->connection->insert('deployments', [
            'request_id' => 11,
            'engineer_id' => 7,
            'deployment_status' => 'active',
        ]);
        $this->connection->insert('users', [
            'user_id' => 7,
            'full_name' => 'Test Engineer',
        ]);
        $this->connection->insert('engineer_profiles', [
            'engineer_id' => 7,
            'current_status' => 'on_duty',
        ]);

        static::getContainer()->set('doctrine.dbal.default_connection', $this->connection);

        return $client;
    }
}
