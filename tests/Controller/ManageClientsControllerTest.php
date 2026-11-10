<?php

namespace App\Tests\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class ManageClientsControllerTest extends WebTestCase
{
    private ?Connection $connection = null;
    private ?string $testDirectory = null;
    private ?KernelBrowser $client = null;

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

    public function testAddClientCreatesLoginAndClientProfile(): void
    {
        $client = $this->createAdminClient();
        $token = $this->csrfToken($client);

        $client->request('POST', '/admin/clients', [
            'csrf_token' => $token,
            'action' => 'add_client',
            'client_name' => 'New Client',
            'client_email' => 'new-client@example.test',
            'client_password' => 'temporary-password',
        ]);

        self::assertResponseRedirects('/admin/clients');
        $user = $this->connection->fetchAssociative(
            "SELECT user_id, full_name, password_hash FROM users WHERE email = 'new-client@example.test'",
        );
        self::assertIsArray($user);
        self::assertSame('New Client', $user['full_name']);
        self::assertTrue(password_verify('temporary-password', $user['password_hash']));
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM client_profiles WHERE client_id = ?',
            [$user['user_id']],
        ));
    }

    public function testClientCanBeEditedAndSuspended(): void
    {
        $clientId = $this->insertClient();
        $client = $this->createAdminClient();

        $client->request('POST', '/admin/clients', [
            'csrf_token' => $this->csrfToken($client),
            'action' => 'edit_client',
            'client_id' => (string) $clientId,
            'edit_name' => 'Updated Client',
            'edit_email' => 'updated@example.test',
            'edit_password' => 'new-temporary-password',
        ]);

        self::assertResponseRedirects('/admin/clients');
        $updated = $this->connection->fetchAssociative(
            'SELECT full_name, email, password_hash FROM users WHERE user_id = ?',
            [$clientId],
        );
        self::assertSame('Updated Client', $updated['full_name']);
        self::assertSame('updated@example.test', $updated['email']);
        self::assertTrue(password_verify('new-temporary-password', $updated['password_hash']));

        $client->request('POST', '/admin/clients', [
            'csrf_token' => $this->csrfToken($client),
            'action' => 'toggle_status',
            'client_id' => (string) $clientId,
            'new_status' => '0',
        ]);

        self::assertResponseRedirects('/admin/clients');
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT is_active FROM users WHERE user_id = ?',
            [$clientId],
        ));
    }

    public function testPurgeRemovesClientAccountAndProfileWhenThereAreNoRecords(): void
    {
        $clientId = $this->insertClient();
        $client = $this->createAdminClient();

        $client->request('POST', '/admin/clients', [
            'csrf_token' => $this->csrfToken($client),
            'action' => 'hard_delete_client',
            'client_id' => (string) $clientId,
        ]);

        self::assertResponseRedirects('/admin/clients');
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM users WHERE user_id = ?',
            [$clientId],
        ));
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM client_profiles WHERE client_id = ?',
            [$clientId],
        ));
    }

    public function testPurgePreservesClientWithDispatchHistory(): void
    {
        $clientId = $this->insertClient();
        $this->connection->insert('dispatch_requests', [
            'client_id' => $clientId,
            'status' => 'resolved',
            'vessel_name' => 'Test Vessel',
            'requested_at' => '2026-01-01 10:00:00',
            'company' => null,
            'contact_person' => 'Test Contact',
            'contact_phone' => '555-0100',
        ]);
        $client = $this->createAdminClient();

        $client->request('POST', '/admin/clients', [
            'csrf_token' => $this->csrfToken($client),
            'action' => 'hard_delete_client',
            'client_id' => (string) $clientId,
        ]);

        self::assertResponseRedirects('/admin/clients');
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM users WHERE user_id = ?',
            [$clientId],
        ));
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM client_profiles WHERE client_id = ?',
            [$clientId],
        ));
    }

    private function createAdminClient(): KernelBrowser
    {
        $client = $this->client;
        self::assertNotNull($client);
        $client->loginUser(new InMemoryUser('test-admin', 'test-password', ['ROLE_ADMIN']));

        $session = $client->getSession();
        $session->set('user_id', 99);
        $session->set('role', 'admin');
        $session->save();

        return $client;
    }

    private function csrfToken(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/admin/clients');

        return (string) $crawler->filter('form input[name="csrf_token"]')->first()->attr('value');
    }

    private function insertClient(): int
    {
        $this->connection->insert('users', [
            'full_name' => 'Existing Client',
            'email' => 'existing@example.test',
            'password_hash' => password_hash('client-password', PASSWORD_DEFAULT),
            'role' => 'client',
            'is_active' => 1,
            'created_at' => '2026-01-01 00:00:00',
        ]);
        $clientId = (int) $this->connection->lastInsertId();
        $this->connection->insert('client_profiles', ['client_id' => $clientId]);

        return $clientId;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->testDirectory = sys_get_temp_dir().'/apex-manage-clients-test-'.bin2hex(random_bytes(8));
        (new Filesystem())->mkdir($this->testDirectory);
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $this->testDirectory.'/clients.sqlite',
        ]);
        $this->connection->executeStatement('CREATE TABLE users (
            user_id INTEGER PRIMARY KEY AUTOINCREMENT,
            full_name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL,
            is_active INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $this->connection->executeStatement('CREATE TABLE client_profiles (
            client_id INTEGER PRIMARY KEY,
            company_name TEXT DEFAULT NULL,
            contact_number TEXT DEFAULT NULL
        )');
        $this->connection->executeStatement('CREATE TABLE dispatch_requests (
            request_id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id INTEGER NOT NULL,
            status TEXT NOT NULL,
            vessel_name TEXT NOT NULL,
            requested_at TEXT NOT NULL,
            company TEXT DEFAULT NULL,
            contact_person TEXT NOT NULL,
            contact_phone TEXT NOT NULL
        )');
        $this->connection->executeStatement('CREATE TABLE invoices (
            invoice_id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id INTEGER NOT NULL
        )');
        static::getContainer()->set('doctrine.dbal.default_connection', $this->connection);
    }
}
