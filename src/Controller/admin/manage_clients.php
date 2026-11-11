<?php

declare(strict_types=1);

namespace App\Controller\admin;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class manage_clients extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/admin/clients', name: 'admin_manage_clients', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $session = $request->getSession();
        $identity = $session->get('user_id');
        $adminId = is_int($identity) || is_string($identity)
            ? filter_var($identity, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;

        $user = $this->getUser();
        if ($user instanceof \App\Entity\User) {
            $adminId = $user->getUserId();
            $session->set('user_id', $adminId);
            $session->set('role', $user->getRole());
            $session->set('full_name', $user->getFullName() ?? $user->getUserIdentifier());
        }

        $role = $session->get('role') ?? ($user instanceof \App\Entity\User ? $user->getRole() : null);
        if ($adminId === false || $role !== 'admin') {
            throw $this->createAccessDeniedException('Administrator access is required.');
        }

        if ($request->isMethod('POST')) {
            return $this->processAction($request);
        }

        $clients = [];
        $clientsLoadError = false;

        try {
            $clients = $this->connection->fetchAllAssociative(<<<'SQL'
                SELECT
                    u.user_id,
                    u.username,
                    u.first_name,
                    u.middle_name,
                    u.surname,
                    TRIM(CONCAT_WS(' ', NULLIF(u.first_name, ''), NULLIF(u.middle_name, ''), NULLIF(u.surname, ''))) AS full_name,
                    u.email,
                    u.is_active,
                    u.created_at,
                    COUNT(d.request_id) AS total_dispatches,
                    COALESCE(SUM(
                        CASE
                            WHEN d.status IN ('pending', 'acknowledged', 'deployed', 'in_progress') THEN 1
                            ELSE 0
                        END
                    ), 0) AS active_dispatches,
                    (
                        SELECT company
                        FROM dispatch_requests
                        WHERE client_id = u.user_id
                        ORDER BY requested_at DESC
                        LIMIT 1
                    ) AS latest_company,
                    (
                        SELECT contact_person
                        FROM dispatch_requests
                        WHERE client_id = u.user_id
                        ORDER BY requested_at DESC
                        LIMIT 1
                    ) AS latest_contact_person,
                    (
                        SELECT contact_phone
                        FROM dispatch_requests
                        WHERE client_id = u.user_id
                        ORDER BY requested_at DESC
                        LIMIT 1
                    ) AS latest_contact_phone
                FROM users u
                LEFT JOIN dispatch_requests d ON u.user_id = d.client_id
                WHERE u.role = 'client'
                GROUP BY u.user_id, u.username, u.first_name, u.middle_name, u.surname, u.email, u.is_active, u.created_at
                ORDER BY u.created_at DESC
                SQL);
        } catch (DBALException $exception) {
            $this->logger->error('Unable to retrieve client authorization roster.', [
                'exception' => $exception,
            ]);
            $clientsLoadError = true;
        }

        return $this->render('admin/manage_clients/index.html.twig', [
            'clients' => $clients,
            'clients_load_error' => $clientsLoadError,
        ]);
    }

    private function processAction(Request $request): Response
    {
        $form = $request->request->all();
        $action = $form['action'] ?? null;

        if (!is_string($action) || !in_array($action, [
            'add_client',
            'edit_client',
            'toggle_status',
            'hard_delete_client',
        ], true)) {
            return $this->redirectToRoute('admin_manage_clients');
        }

        $token = $form['csrf_token'] ?? $form['_token'] ?? $form['_csrf_token'] ?? null;
        if (!is_string($token) || !$this->isCsrfTokenValid('admin_clients', $token)) {
            $this->addFlash('danger', 'Security validation failed. Unauthorized request.');

            return $this->redirectToRoute('admin_manage_clients');
        }

        return match ($action) {
            'add_client' => $this->addClient($form),
            'edit_client' => $this->editClient($form),
            'toggle_status' => $this->toggleClientStatus($form),
            'hard_delete_client' => $this->deleteClient($form),
        };
    }

    private function addClient(array $form): Response
    {
        $username = $this->stringField($form, 'client_username');
        $firstName = \App\Entity\User::capitalizeName($this->stringField($form, 'client_first_name'));
        $middleRaw = \App\Entity\User::capitalizeName($this->stringField($form, 'client_middle_name'));
        $middleName = $middleRaw !== '' ? $middleRaw : null;
        $surname = \App\Entity\User::capitalizeName($this->stringField($form, 'client_surname'));

        if ($firstName === '' && $surname === '' && isset($form['client_name'])) {
            $split = $this->splitFullName($this->stringField($form, 'client_name'));
            $firstName = $split['first_name'];
            $middleName = $split['middle_name'];
            $surname = $split['surname'];
        }

        if ($username === '' && $firstName !== '') {
            $username = strtolower((string) preg_replace('/[^a-zA-Z0-9_.-]/', '', $firstName.'_'.$surname));
        }

        $email = $this->stringField($form, 'client_email');
        $password = $form['client_password'] ?? null;

        if ($username === '' || $firstName === '' || $surname === '' || $email === '' || !is_string($password) || $password === '') {
            $this->addFlash('warning', 'Username, First Name, Surname, Email, and Password are required to register a client.');

            return $this->redirectToRoute('admin_manage_clients');
        }

        $usernameError = \App\Security\CredentialSecurityPolicy::validateUsername($username);
        if ($usernameError !== null) {
            $this->addFlash('warning', $usernameError);

            return $this->redirectToRoute('admin_manage_clients');
        }

        $email = \App\Security\CredentialSecurityPolicy::normalizeEmail($email);
        $emailError = \App\Security\CredentialSecurityPolicy::validateEmail($email);
        if ($emailError !== null) {
            $this->addFlash('warning', $emailError);

            return $this->redirectToRoute('admin_manage_clients');
        }

        $passwordError = \App\Security\CredentialSecurityPolicy::validatePassword($password, $email);
        if ($passwordError !== null) {
            $this->addFlash('warning', $passwordError);

            return $this->redirectToRoute('admin_manage_clients');
        }

        try {
            if ($this->connection->fetchOne(
                'SELECT user_id FROM users WHERE email = ? OR username = ? LIMIT 1',
                [$email, $username],
            )) {
                $this->addFlash('danger', 'Registration halted: Email or Username already exists in the system.');

                return $this->redirectToRoute('admin_manage_clients');
            }

            $this->connection->transactional(function (Connection $connection) use ($username, $firstName, $middleName, $surname, $email, $password): void {
                $connection->insert('users', [
                    'username' => $username,
                    'first_name' => $firstName,
                    'middle_name' => $middleName,
                    'surname' => $surname,
                    'email' => $email,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'role' => 'client',
                    'is_active' => 1,
                ]);

                $clientId = $connection->lastInsertId();
                if ($clientId === false || $clientId === '0') {
                    throw new \RuntimeException('The database did not return the new client ID.');
                }

                $connection->insert('client_profiles', [
                    'client_id' => $clientId,
                ]);
            });
        } catch (DBALException $exception) {
            $this->logger->error('Unable to create a client account.', ['exception' => $exception]);
            $this->addFlash('danger', 'Unable to create the client account. Please try again later.');

            return $this->redirectToRoute('admin_manage_clients');
        }

        $this->addFlash('success', 'Client profile created successfully.');

        return $this->redirectToRoute('admin_manage_clients');
    }

    private function editClient(array $form): Response
    {
        $clientId = $this->positiveId($form['client_id'] ?? null);
        $firstName = \App\Entity\User::capitalizeName($this->stringField($form, 'edit_first_name'));
        $middleRaw = \App\Entity\User::capitalizeName($this->stringField($form, 'edit_middle_name'));
        $middleName = $middleRaw !== '' ? $middleRaw : null;
        $surname = \App\Entity\User::capitalizeName($this->stringField($form, 'edit_surname'));

        if ($firstName === '' && $surname === '' && isset($form['edit_name'])) {
            $split = $this->splitFullName($this->stringField($form, 'edit_name'));
            $firstName = $split['first_name'];
            $middleName = $split['middle_name'];
            $surname = $split['surname'];
        }

        $email = $this->stringField($form, 'edit_email');
        $password = $form['edit_password'] ?? '';

        if ($clientId === null || $firstName === '' || $surname === '' || $email === '' || !is_string($password)) {
            $this->addFlash('warning', 'First Name, Surname, and Email are required.');

            return $this->redirectToRoute('admin_manage_clients');
        }

        $email = \App\Security\CredentialSecurityPolicy::normalizeEmail($email);
        $emailError = \App\Security\CredentialSecurityPolicy::validateEmail($email);
        if ($emailError !== null) {
            $this->addFlash('warning', $emailError);

            return $this->redirectToRoute('admin_manage_clients');
        }

        if ($password !== '') {
            $passwordError = \App\Security\CredentialSecurityPolicy::validatePassword($password, $email);
            if ($passwordError !== null) {
                $this->addFlash('warning', $passwordError);

                return $this->redirectToRoute('admin_manage_clients');
            }
        }

        try {
            if (!$this->connection->fetchOne(
                "SELECT user_id FROM users WHERE user_id = ? AND role = 'client'",
                [$clientId],
            )) {
                $this->addFlash('danger', 'Client account was not found.');

                return $this->redirectToRoute('admin_manage_clients');
            }

            if ($this->connection->fetchOne(
                'SELECT user_id FROM users WHERE email = ? AND user_id != ? LIMIT 1',
                [$email, $clientId],
            )) {
                $this->addFlash('danger', 'Update halted: That email is already in use by another account.');

                return $this->redirectToRoute('admin_manage_clients');
            }

            $userData = [
                'first_name' => $firstName,
                'middle_name' => $middleName,
                'surname' => $surname,
                'email' => $email,
            ];
            if ($password !== '') {
                $userData['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            }
            $this->connection->update('users', $userData, [
                'user_id' => $clientId,
                'role' => 'client',
            ]);
        } catch (DBALException $exception) {
            $this->logger->error('Unable to update a client account.', [
                'client_id' => $clientId,
                'exception' => $exception,
            ]);
            $this->addFlash('danger', 'Unable to update the client account. Please try again later.');

            return $this->redirectToRoute('admin_manage_clients');
        }

        $this->addFlash('success', 'Client profile updated successfully.');

        return $this->redirectToRoute('admin_manage_clients');
    }

    private function toggleClientStatus(array $form): Response
    {
        $clientId = $this->positiveId($form['client_id'] ?? null);
        $newStatus = $form['new_status'] ?? null;
        if ($clientId === null || !in_array($newStatus, ['0', '1', 0, 1], true)) {
            $this->addFlash('warning', 'Invalid client status action.');

            return $this->redirectToRoute('admin_manage_clients');
        }

        $isActive = (int) $newStatus;
        try {
            if (!$this->connection->fetchOne(
                "SELECT user_id FROM users WHERE user_id = ? AND role = 'client'",
                [$clientId],
            )) {
                $this->addFlash('danger', 'Client account was not found.');

                return $this->redirectToRoute('admin_manage_clients');
            }

            $this->connection->update('users', [
                'is_active' => $isActive,
            ], [
                'user_id' => $clientId,
                'role' => 'client',
            ]);
        } catch (DBALException $exception) {
            $this->logger->error('Unable to change client account status.', [
                'client_id' => $clientId,
                'exception' => $exception,
            ]);
            $this->addFlash('danger', 'Unable to change the client account status. Please try again later.');

            return $this->redirectToRoute('admin_manage_clients');
        }

        $this->addFlash(
            $isActive === 1 ? 'success' : 'warning',
            $isActive === 1
                ? 'Client account has been reactivated.'
                : 'Client account has been suspended.',
        );

        return $this->redirectToRoute('admin_manage_clients');
    }

    private function deleteClient(array $form): Response
    {
        $clientId = $this->positiveId($form['client_id'] ?? null);
        if ($clientId === null) {
            $this->addFlash('warning', 'Invalid client account.');

            return $this->redirectToRoute('admin_manage_clients');
        }

        try {
            $clientExists = $this->connection->fetchOne(
                "SELECT user_id FROM users WHERE user_id = ? AND role = 'client'",
                [$clientId],
            );
            if (!$clientExists) {
                $this->addFlash('danger', 'Client account was not found.');

                return $this->redirectToRoute('admin_manage_clients');
            }

            $deleted = self::purgeClientAccountAndHistory($this->connection, $clientId);
        } catch (DBALException $exception) {
            $this->logger->error('Unable to permanently delete a client account.', [
                'client_id' => $clientId,
                'exception' => $exception,
            ]);
            $this->addFlash(
                'danger',
                'Unable to purge this client. Check its related records and try again later.',
            );

            return $this->redirectToRoute('admin_manage_clients');
        }

        if ($deleted === 0) {
            $this->addFlash('danger', 'Client account was not found.');

            return $this->redirectToRoute('admin_manage_clients');
        }

        $this->addFlash(
            'success',
            'Client account and all connected dispatch, deployment, timeline, and billing history have been permanently erased.',
        );

        return $this->redirectToRoute('admin_manage_clients');
    }

    /**
     * Permanently deletes a client account along with all connected history
     * (operation updates, deployments, invoices, dispatch requests, and client profile),
     * and releases any engineers who were deployed exclusively on this client's operations.
     */
    public static function purgeClientAccountAndHistory(Connection $connection, int $clientId): int
    {
        return $connection->transactional(function (Connection $conn) use ($clientId): int {
            $requestIds = array_map(
                'intval',
                $conn->fetchFirstColumn(
                    'SELECT request_id FROM dispatch_requests WHERE client_id = ?',
                    [$clientId],
                ),
            );

            $affectedEngineerIds = [];
            if ($requestIds !== []) {
                $placeholders = implode(', ', array_fill(0, count($requestIds), '?'));

                $affectedEngineerIds = array_values(array_unique(array_map(
                    'intval',
                    $conn->fetchFirstColumn(
                        "SELECT DISTINCT engineer_id FROM deployments WHERE request_id IN ($placeholders) AND engineer_id IS NOT NULL",
                        $requestIds,
                    ),
                )));

                $conn->executeStatement(
                    "DELETE FROM operation_updates WHERE request_id IN ($placeholders)",
                    $requestIds,
                );
                $conn->executeStatement(
                    "DELETE FROM deployments WHERE request_id IN ($placeholders)",
                    $requestIds,
                );
                $conn->executeStatement(
                    "DELETE FROM invoices WHERE request_id IN ($placeholders)",
                    $requestIds,
                );
            }

            $conn->executeStatement(
                'DELETE FROM operation_updates WHERE updater_id = ?',
                [$clientId],
            );
            $conn->executeStatement(
                'DELETE FROM invoices WHERE client_id = ?',
                [$clientId],
            );
            $conn->executeStatement(
                'DELETE FROM dispatch_requests WHERE client_id = ?',
                [$clientId],
            );
            $conn->executeStatement(
                'DELETE FROM client_profiles WHERE client_id = ?',
                [$clientId],
            );

            foreach ($affectedEngineerIds as $engineerId) {
                if ($engineerId <= 0) {
                    continue;
                }
                $remainingActive = (int) $conn->fetchOne(
                    "SELECT COUNT(*) FROM deployments WHERE engineer_id = ? AND deployment_status <> 'completed'",
                    [$engineerId],
                );
                if ($remainingActive === 0) {
                    $conn->executeStatement(
                        "UPDATE engineer_profiles SET current_status = 'available' WHERE engineer_id = ? AND current_status = 'deployed'",
                        [$engineerId],
                    );
                }
            }

            return (int) $conn->delete('users', [
                'user_id' => $clientId,
                'role' => 'client',
            ]);
        });
    }

    private function stringField(array $form, string $key): string
    {
        $value = $form[$key] ?? null;

        return is_string($value) ? trim($value) : '';
    }

    private function positiveId(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }

    /**
     * @return array{first_name: string, middle_name: ?string, surname: string}
     */
    private function splitFullName(string $fullName): array
    {
        $parts = preg_split('/\s+/', \App\Entity\User::capitalizeName($fullName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return ['first_name' => '', 'middle_name' => null, 'surname' => ''];
        }
        if (count($parts) === 1) {
            return ['first_name' => $parts[0], 'middle_name' => null, 'surname' => ''];
        }
        if (count($parts) === 2) {
            return ['first_name' => $parts[0], 'middle_name' => null, 'surname' => $parts[1]];
        }

        $firstName = (string) array_shift($parts);
        $surname = (string) array_pop($parts);
        $middleName = trim(implode(' ', $parts));

        return [
            'first_name' => $firstName,
            'middle_name' => $middleName !== '' ? $middleName : null,
            'surname' => $surname,
        ];
    }
}