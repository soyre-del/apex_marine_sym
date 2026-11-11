<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Ensures the `users` database table schema has `username`, `first_name`, `middle_name` (nullable),
 * and `surname` in place of `full_name`, migrating any existing rows seamlessly.
 */
final class UserTableSchemaSubscriber implements EventSubscriberInterface
{
    private bool $checked = false;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Run before Symfony's FirewallListener (priority 8) loads the User entity
            KernelEvents::REQUEST => ['onKernelRequest', 512],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $this->checked) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        if (str_starts_with($path, '/_wdt') || str_starts_with($path, '/_profiler') || str_starts_with($path, '/assets/')) {
            return;
        }

        $this->checked = true;

        try {
            $columns = $this->connection->fetchFirstColumn('SHOW COLUMNS FROM users');
            $columnMap = array_flip(array_map('strtolower', $columns));

            $needsAlter = !isset($columnMap['username'])
                || !isset($columnMap['first_name'])
                || !isset($columnMap['middle_name'])
                || !isset($columnMap['surname'])
                || isset($columnMap['full_name']);

            if ($needsAlter) {
                if (!isset($columnMap['username'])) {
                    $this->connection->executeStatement(
                        'ALTER TABLE users ADD COLUMN username VARCHAR(60) DEFAULT NULL AFTER user_id'
                    );
                }

                if (!isset($columnMap['first_name'])) {
                    $this->connection->executeStatement(
                        "ALTER TABLE users ADD COLUMN first_name VARCHAR(100) NOT NULL DEFAULT '' AFTER username"
                    );
                }

                if (!isset($columnMap['middle_name'])) {
                    $this->connection->executeStatement(
                        'ALTER TABLE users ADD COLUMN middle_name VARCHAR(100) DEFAULT NULL AFTER first_name'
                    );
                }

                if (!isset($columnMap['surname'])) {
                    $this->connection->executeStatement(
                        "ALTER TABLE users ADD COLUMN surname VARCHAR(100) NOT NULL DEFAULT '' AFTER middle_name"
                    );
                }

                if (isset($columnMap['full_name'])) {
                    $rows = $this->connection->fetchAllAssociative(
                        'SELECT user_id, username, full_name, first_name, middle_name, surname, email FROM users'
                    );

                    foreach ($rows as $row) {
                        $existingFirst = trim((string) ($row['first_name'] ?? ''));
                        $rawFullName = trim((string) ($row['full_name'] ?? ''));

                        if ($existingFirst === '' && $rawFullName !== '') {
                            $parts = preg_split('/\s+/', \App\Entity\User::capitalizeName($rawFullName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                            if ($parts === []) {
                                $firstName = 'User';
                                $middleName = null;
                                $surname = '';
                            } elseif (count($parts) === 1) {
                                $firstName = $parts[0];
                                $middleName = null;
                                $surname = '';
                            } elseif (count($parts) === 2) {
                                $firstName = $parts[0];
                                $middleName = null;
                                $surname = $parts[1];
                            } else {
                                $firstName = (string) array_shift($parts);
                                $surname = (string) array_pop($parts);
                                $mid = trim(implode(' ', $parts));
                                $middleName = $mid !== '' ? $mid : null;
                            }

                            $this->connection->update('users', [
                                'first_name' => $firstName,
                                'middle_name' => $middleName,
                                'surname' => $surname,
                            ], [
                                'user_id' => $row['user_id'],
                            ]);
                        }
                    }

                    $this->connection->executeStatement('ALTER TABLE users DROP COLUMN full_name');
                }

                // Backfill missing usernames from first_name or email prefix
                $missingUsernames = $this->connection->fetchAllAssociative(
                    "SELECT user_id, first_name, email FROM users WHERE username IS NULL OR TRIM(username) = ''"
                );
                foreach ($missingUsernames as $row) {
                    $emailPrefix = explode('@', (string) ($row['email'] ?? ''))[0] ?? '';
                    $candidate = preg_replace('/[^a-zA-Z0-9_.-]/', '', (string) ($row['first_name'] ?: $emailPrefix));
                    if ($candidate === '' || strlen($candidate) < 3) {
                        $candidate = 'user'.$row['user_id'];
                    }
                    $this->connection->update('users', [
                        'username' => $candidate,
                    ], [
                        'user_id' => $row['user_id'],
                    ]);
                }
            }

            $this->purgeNonLaurenceClientsOnce();
        } catch (\Throwable) {
            // Ignore if database is not yet reachable
        }
    }

    /**
     * One-time cleanup that deletes all client accounts and their connected history
     * except the Laurence client account.
     */
    private function purgeNonLaurenceClientsOnce(): void
    {
        $tableStatus = $this->connection->fetchAssociative("SHOW TABLE STATUS LIKE 'client_profiles'");
        $tableComment = is_array($tableStatus) ? (string) ($tableStatus['Comment'] ?? '') : '';
        if (str_contains($tableComment, 'laurence_cleanup_v2')) {
            return;
        }

        $nonLaurenceClientIds = array_map(
            'intval',
            $this->connection->fetchFirstColumn(<<<'SQL'
                SELECT user_id
                FROM users
                WHERE role = 'client'
                  AND LOWER(COALESCE(first_name, '')) NOT LIKE '%laurence%'
                  AND LOWER(COALESCE(username, '')) NOT LIKE '%laurence%'
                  AND LOWER(COALESCE(surname, '')) NOT LIKE '%laurence%'
                  AND LOWER(COALESCE(email, '')) NOT LIKE '%laurence%'
                SQL),
        );

        foreach ($nonLaurenceClientIds as $clientId) {
            if ($clientId > 0) {
                \App\Controller\admin\manage_clients::purgeClientAccountAndHistory($this->connection, $clientId);
            }
        }

        // Also remove any orphaned dispatch_requests, invoices, deployments, operation_updates, or client_profiles
        // not belonging to a remaining Laurence client account
        $laurenceClientIds = array_map(
            'intval',
            $this->connection->fetchFirstColumn("SELECT user_id FROM users WHERE role = 'client'"),
        );

        $this->connection->transactional(function (Connection $conn) use ($laurenceClientIds): void {
            if ($laurenceClientIds === []) {
                $conn->executeStatement('DELETE FROM operation_updates');
                $conn->executeStatement('DELETE FROM deployments');
                $conn->executeStatement('DELETE FROM invoices');
                $conn->executeStatement('DELETE FROM dispatch_requests');
                $conn->executeStatement('DELETE FROM client_profiles');
            } else {
                $placeholders = implode(', ', array_fill(0, count($laurenceClientIds), '?'));

                $orphanedRequestIds = array_map(
                    'intval',
                    $conn->fetchFirstColumn(
                        "SELECT request_id FROM dispatch_requests WHERE client_id NOT IN ($placeholders)",
                        $laurenceClientIds,
                    ),
                );

                if ($orphanedRequestIds !== []) {
                    $reqPlaceholders = implode(', ', array_fill(0, count($orphanedRequestIds), '?'));
                    $conn->executeStatement(
                        "DELETE FROM operation_updates WHERE request_id IN ($reqPlaceholders)",
                        $orphanedRequestIds,
                    );
                    $conn->executeStatement(
                        "DELETE FROM deployments WHERE request_id IN ($reqPlaceholders)",
                        $orphanedRequestIds,
                    );
                    $conn->executeStatement(
                        "DELETE FROM invoices WHERE request_id IN ($reqPlaceholders)",
                        $orphanedRequestIds,
                    );
                    $conn->executeStatement(
                        "DELETE FROM dispatch_requests WHERE request_id IN ($reqPlaceholders)",
                        $orphanedRequestIds,
                    );
                }

                $conn->executeStatement(
                    "DELETE FROM invoices WHERE client_id NOT IN ($placeholders)",
                    $laurenceClientIds,
                );
                $conn->executeStatement(
                    "DELETE FROM client_profiles WHERE client_id NOT IN ($placeholders)",
                    $laurenceClientIds,
                );
            }

            // Reset any engineer marked as deployed who no longer has an active deployment
            $conn->executeStatement(<<<'SQL'
                UPDATE engineer_profiles ep
                SET ep.current_status = 'available'
                WHERE ep.current_status = 'deployed'
                  AND NOT EXISTS (
                      SELECT 1
                      FROM deployments d
                      WHERE d.engineer_id = ep.engineer_id
                        AND d.deployment_status <> 'completed'
                  )
                SQL);
        });

        $this->connection->executeStatement("ALTER TABLE client_profiles COMMENT = 'laurence_cleanup_v2'");
    }
}
