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

        if ($adminId === false || $session->get('role') !== 'admin') {
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
                    u.full_name,
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
                GROUP BY u.user_id
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

        $token = $form['csrf_token'] ?? null;
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
        $name = $this->stringField($form, 'client_name');
        $email = $this->stringField($form, 'client_email');
        $password = $form['client_password'] ?? null;

        if ($name === '' || $email === '' || !is_string($password) || $password === '') {
            $this->addFlash('warning', 'All fields are required to register a client.');

            return $this->redirectToRoute('admin_manage_clients');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->addFlash('warning', 'Enter a valid email address.');

            return $this->redirectToRoute('admin_manage_clients');
        }
        if (strlen($password) < 8) {
            $this->addFlash('warning', 'Access Code must be at least 8 characters.');

            return $this->redirectToRoute('admin_manage_clients');
        }

        try {
            if ($this->connection->fetchOne(
                'SELECT user_id FROM users WHERE email = ? LIMIT 1',
                [$email],
            )) {
                $this->addFlash('danger', 'Registration halted: Email already exists in the system.');

                return $this->redirectToRoute('admin_manage_clients');
            }

            $this->connection->transactional(function (Connection $connection) use ($name, $email, $password): void {
                $connection->insert('users', [
                    'full_name' => $name,
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

        $this->addFlash('success', sprintf('Success: Client profile for "%s" created.', $name));

        return $this->redirectToRoute('admin_manage_clients');
    }

    private function editClient(array $form): Response
    {
        $clientId = $this->positiveId($form['client_id'] ?? null);
        $name = $this->stringField($form, 'edit_name');
        $email = $this->stringField($form, 'edit_email');
        $password = $form['edit_password'] ?? '';

        if ($clientId === null || $name === '' || $email === '' || !is_string($password)) {
            $this->addFlash('warning', 'Enter a valid client name, email, and account.');

            return $this->redirectToRoute('admin_manage_clients');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->addFlash('warning', 'Enter a valid email address.');

            return $this->redirectToRoute('admin_manage_clients');
        }
        if ($password !== '' && strlen($password) < 8) {
            $this->addFlash('warning', 'A new password must be at least 8 characters.');

            return $this->redirectToRoute('admin_manage_clients');
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
                'full_name' => $name,
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

            $hasDispatches = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM dispatch_requests WHERE client_id = ?',
                [$clientId],
            ) > 0;
            $hasInvoices = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM invoices WHERE client_id = ?',
                [$clientId],
            ) > 0;
            if ($hasDispatches || $hasInvoices) {
                $this->addFlash(
                    'warning',
                    'This client has dispatch or invoice records and cannot be purged. Suspend the account to revoke access without deleting its history.',
                );

                return $this->redirectToRoute('admin_manage_clients');
            }

            $deleted = $this->connection->transactional(function (Connection $connection) use ($clientId): int {
                $connection->delete('client_profiles', ['client_id' => $clientId]);

                return $connection->delete('users', [
                    'user_id' => $clientId,
                    'role' => 'client',
                ]);
            });
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
            'Client account permanently erased.',
        );

        return $this->redirectToRoute('admin_manage_clients');
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
}