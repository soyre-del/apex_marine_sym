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

final class billing extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/admin/billing', name: 'admin_billing', methods: ['GET', 'POST'])]
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

        // Ensure all dispatch requests and invoices are strictly linked to client accounts (never engineers)
        $this->sanitizeClientAssignments();

        if ($request->isMethod('POST')) {
            return $this->processAction($request);
        }

        $unbilledRequests = [];
        $invoices = [];
        $clients = [];
        $totalRevenue = 0.0;
        $pendingRevenue = 0.0;
        $unbilledLoadError = false;
        $invoicesLoadError = false;

        try {
            $clients = $this->connection->fetchAllAssociative(<<<'SQL'
                SELECT
                    u.user_id,
                    TRIM(CONCAT_WS(' ', NULLIF(u.first_name, ''), NULLIF(u.middle_name, ''), NULLIF(u.surname, ''))) AS full_name,
                    u.email,
                    COALESCE(NULLIF(cp.company_name, ''), 'Apex Fleet Partner') AS company_name
                FROM users u
                LEFT JOIN client_profiles cp ON u.user_id = cp.client_id
                WHERE u.role = 'client' AND u.is_active = 1
                ORDER BY u.first_name ASC, u.surname ASC
                SQL);
        } catch (DBALException $exception) {
            $this->logger->error('Unable to retrieve active clients for billing.', [
                'exception' => $exception,
            ]);
        }

        try {
            $unbilledRequests = $this->connection->fetchAllAssociative(<<<'SQL'
                SELECT
                    d.request_id,
                    d.vessel_name,
                    d.vessel_type,
                    d.service_type,
                    d.company,
                    d.contact_person,
                    d.description,
                    d.status,
                    d.requested_at,
                    u.user_id AS client_id,
                    TRIM(CONCAT_WS(' ', NULLIF(u.first_name, ''), NULLIF(u.middle_name, ''), NULLIF(u.surname, ''))) AS client_name,
                    u.email AS client_email,
                    COALESCE(NULLIF(d.company, ''), NULLIF(cp.company_name, ''), 'Apex Fleet Partner') AS client_company,
                    (
                        SELECT status_milestone
                        FROM operation_updates
                        WHERE request_id = d.request_id
                        ORDER BY logged_at DESC
                        LIMIT 1
                    ) AS latest_milestone,
                    COALESCE(
                        (
                            SELECT detailed_message
                            FROM operation_updates
                            WHERE request_id = d.request_id
                            ORDER BY logged_at DESC
                            LIMIT 1
                        ),
                        d.description
                    ) AS problem_description
                FROM dispatch_requests d
                JOIN users u ON d.client_id = u.user_id AND u.role = 'client'
                LEFT JOIN client_profiles cp ON u.user_id = cp.client_id
                LEFT JOIN invoices i ON d.request_id = i.request_id
                WHERE i.invoice_id IS NULL
                    AND d.status <> 'cancelled'
                    AND d.is_active = 1
                ORDER BY
                    CASE
                        WHEN d.status = 'resolved' THEN 1
                        WHEN d.status IN ('in_progress', 'deployed') THEN 2
                        ELSE 3
                    END,
                    d.requested_at DESC
                SQL);
        } catch (DBALException $exception) {
            $this->logger->error('Unable to retrieve unbilled dispatch requests.', [
                'exception' => $exception,
            ]);
            $unbilledLoadError = true;
        }

        try {
            $invoices = $this->connection->fetchAllAssociative(<<<'SQL'
                SELECT
                    i.invoice_id,
                    i.amount,
                    i.currency,
                    i.payment_status,
                    i.payment_method,
                    i.reference_number,
                    i.created_at,
                    TRIM(CONCAT_WS(' ', NULLIF(u.first_name, ''), NULLIF(u.middle_name, ''), NULLIF(u.surname, ''))) AS client_name,
                    u.email AS client_email,
                    COALESCE(NULLIF(d.company, ''), NULLIF(cp.company_name, ''), 'Apex Fleet Partner') AS client_company,
                    d.request_id,
                    d.vessel_name,
                    d.service_type
                FROM invoices i
                JOIN users u ON i.client_id = u.user_id AND u.role = 'client'
                LEFT JOIN client_profiles cp ON u.user_id = cp.client_id
                JOIN dispatch_requests d ON i.request_id = d.request_id
                ORDER BY i.created_at DESC
                SQL);

            foreach ($invoices as $invoice) {
                $amount = (float) $invoice['amount'];
                if ($invoice['payment_status'] === 'paid') {
                    $totalRevenue += $amount;
                } elseif ($invoice['payment_status'] === 'unpaid') {
                    $pendingRevenue += $amount;
                }
            }
        } catch (DBALException $exception) {
            $this->logger->error('Unable to retrieve billing invoices.', [
                'exception' => $exception,
            ]);
            $invoicesLoadError = true;
        }

        return $this->render('admin/billing/index.html.twig', [
            'unbilled_requests' => $unbilledRequests,
            'unbilled_load_error' => $unbilledLoadError,
            'invoices' => $invoices,
            'invoices_load_error' => $invoicesLoadError,
            'clients' => $clients,
            'total_revenue' => $totalRevenue,
            'pending_revenue' => $pendingRevenue,
        ]);
    }

    private function processAction(Request $request): Response
    {
        $form = $request->request->all();
        $action = $form['action'] ?? null;

        if (!is_string($action) || $action !== 'create_invoice') {
            return $this->redirectToRoute('admin_billing');
        }

        $token = $form['_token'] ?? $form['csrf_token'] ?? $form['_csrf_token'] ?? null;
        if (!is_string($token) || !$this->isCsrfTokenValid('admin_billing', $token)) {
            $this->addFlash('danger', 'Security validation failed. Please reload the page and try again.');

            return $this->redirectToRoute('admin_billing');
        }

        return $this->createInvoice($form);
    }

    private function createInvoice(array $form): Response
    {
        $requestId = $this->positiveId($form['request_id'] ?? null);
        $selectedClientId = $this->positiveId($form['client_id'] ?? null);
        $amountRaw = is_string($form['amount'] ?? null)
            ? str_replace([',', '$', ' '], '', trim($form['amount']))
            : ($form['amount'] ?? null);
        $currency = isset($form['currency']) && is_string($form['currency']) && trim($form['currency']) !== ''
            ? strtoupper(trim($form['currency']))
            : 'USD';

        if ($requestId === null
            || (!is_string($amountRaw) && !is_int($amountRaw) && !is_float($amountRaw))
            || !is_numeric($amountRaw)
            || !is_finite((float) $amountRaw)
            || (float) $amountRaw <= 0
            || $currency !== 'USD') {
            $this->addFlash('warning', 'Please select a valid client operation and enter a positive invoice amount.');

            return $this->redirectToRoute('admin_billing');
        }

        $amount = round((float) $amountRaw, 2);

        try {
            $dispatchRow = $this->connection->fetchAssociative(
                'SELECT request_id, client_id, company, contact_phone FROM dispatch_requests WHERE request_id = ? LIMIT 1',
                [$requestId],
            );

            if ($dispatchRow === false) {
                $this->addFlash('danger', 'Selected dispatch request was not found.');

                return $this->redirectToRoute('admin_billing');
            }

            $existingInvoice = $this->connection->fetchOne(
                'SELECT invoice_id FROM invoices WHERE request_id = ? LIMIT 1',
                [$requestId],
            );
            if ($existingInvoice !== false && $existingInvoice !== null) {
                $this->addFlash('warning', sprintf('An invoice has already been generated for REQ-%04d.', $requestId));

                return $this->redirectToRoute('admin_billing');
            }

            // Determine the client_id to bill, ensuring it strictly belongs to a user with role = 'client'
            $candidateClientId = $selectedClientId ?? (int) $dispatchRow['client_id'];
            $validClient = $this->connection->fetchAssociative(
                "SELECT user_id, TRIM(CONCAT_WS(' ', NULLIF(first_name, ''), NULLIF(middle_name, ''), NULLIF(surname, ''))) AS full_name FROM users WHERE user_id = ? AND role = 'client' LIMIT 1",
                [$candidateClientId],
            );

            if ($validClient === false) {
                // Fallback to the dispatch request's client if valid, or first active client
                $validClient = $this->connection->fetchAssociative(
                    "SELECT user_id, TRIM(CONCAT_WS(' ', NULLIF(first_name, ''), NULLIF(middle_name, ''), NULLIF(surname, ''))) AS full_name FROM users WHERE user_id = ? AND role = 'client' LIMIT 1",
                    [(int) $dispatchRow['client_id']],
                );
            }

            if ($validClient === false) {
                $validClient = $this->connection->fetchAssociative(
                    "SELECT user_id, TRIM(CONCAT_WS(' ', NULLIF(first_name, ''), NULLIF(middle_name, ''), NULLIF(surname, ''))) AS full_name FROM users WHERE role = 'client' ORDER BY is_active DESC, user_id ASC LIMIT 1",
                );
            }

            if ($validClient === false) {
                $this->addFlash('danger', 'Cannot generate invoice: No valid client account exists. Invoices can only be issued to clients, never engineers.');

                return $this->redirectToRoute('admin_billing');
            }

            $clientId = (int) $validClient['user_id'];
            $clientName = (string) $validClient['full_name'];

            $this->connection->transactional(function (Connection $connection) use (
                $requestId,
                $clientId,
                $amount,
                $currency,
                $dispatchRow,
            ): void {
                // Ensure client_profiles entry exists for the client to satisfy foreign key constraints
                $hasClientProfile = $connection->fetchOne(
                    'SELECT client_id FROM client_profiles WHERE client_id = ? LIMIT 1',
                    [$clientId],
                );
                if ($hasClientProfile === false || $hasClientProfile === null) {
                    $connection->insert('client_profiles', [
                        'client_id' => $clientId,
                        'company_name' => !empty($dispatchRow['company']) ? (string) $dispatchRow['company'] : 'Apex Fleet Partner',
                        'contact_number' => !empty($dispatchRow['contact_phone']) ? (string) $dispatchRow['contact_phone'] : null,
                    ]);
                }

                // Keep dispatch_requests.client_id synchronized with the billed client account
                if ((int) $dispatchRow['client_id'] !== $clientId) {
                    $connection->update('dispatch_requests', [
                        'client_id' => $clientId,
                    ], [
                        'request_id' => $requestId,
                    ]);
                }

                $connection->insert('invoices', [
                    'request_id' => $requestId,
                    'client_id' => $clientId,
                    'amount' => $amount,
                    'currency' => $currency,
                    'payment_status' => 'unpaid',
                ]);
            });
        } catch (DBALException $exception) {
            $this->logger->error('Unable to create an invoice.', [
                'request_id' => $requestId,
                'exception' => $exception,
            ]);
            $this->addFlash('danger', 'Failed to generate invoice due to a database error.');

            return $this->redirectToRoute('admin_billing');
        }

        $this->addFlash('success', sprintf(
            'Invoice generated for client %s (REQ-%04d - USD %s).',
            $clientName,
            $requestId,
            number_format($amount, 2, '.', ','),
        ));

        return $this->redirectToRoute('admin_billing');
    }

    private function markInvoicePaid(array $form): Response
    {
        $invoiceId = $this->positiveId($form['invoice_id'] ?? null);
        $method = $form['payment_method'] ?? 'Manual Admin Override';
        if ($invoiceId === null || !is_string($method) || trim($method) === '' || strlen($method) > 100) {
            $this->addFlash('warning', 'Enter a valid invoice and payment method.');

            return $this->redirectToRoute('admin_billing');
        }

        try {
            $updated = $this->connection->executeStatement(
                "UPDATE invoices
                SET payment_status = ?, payment_method = ?
                WHERE invoice_id = ? AND payment_status <> 'paid'",
                ['paid', trim($method), $invoiceId],
            );
        } catch (DBALException $exception) {
            $this->logger->error('Unable to update invoice payment status.', [
                'invoice_id' => $invoiceId,
                'exception' => $exception,
            ]);
            $this->addFlash('danger', 'Failed to update invoice status. Please try again later.');

            return $this->redirectToRoute('admin_billing');
        }

        if ($updated > 0) {
            $this->addFlash('success', sprintf(
                'Invoice INV-%05d marked as paid.',
                $invoiceId,
            ));
        } else {
            $this->addFlash('warning', 'This invoice is already marked as paid or could not be found.');
        }

        return $this->redirectToRoute('admin_billing');
    }

    /**
     * Ensures that dispatch_requests and invoices are strictly associated with 'client' role accounts,
     * reassigning any records that were inadvertently created while an engineer or admin was logged in.
     */
    private function sanitizeClientAssignments(): void
    {
        try {
            $misassignedCount = (int) $this->connection->fetchOne(<<<'SQL'
                SELECT COUNT(*)
                FROM dispatch_requests d
                JOIN users u ON d.client_id = u.user_id
                WHERE u.role <> 'client'
                SQL);

            $misassignedInvoices = (int) $this->connection->fetchOne(<<<'SQL'
                SELECT COUNT(*)
                FROM invoices i
                JOIN users u ON i.client_id = u.user_id
                WHERE u.role <> 'client'
                SQL);

            if ($misassignedCount === 0 && $misassignedInvoices === 0) {
                return;
            }

            // Find an existing active client account
            $defaultClientId = $this->connection->fetchOne(
                "SELECT user_id FROM users WHERE role = 'client' ORDER BY is_active DESC, user_id ASC LIMIT 1"
            );

            if ($defaultClientId === false || $defaultClientId === null) {
                return;
            }

            $clientId = (int) $defaultClientId;
            if ($clientId <= 0) {
                return;
            }

            $hasProfile = $this->connection->fetchOne(
                'SELECT client_id FROM client_profiles WHERE client_id = ? LIMIT 1',
                [$clientId],
            );
            if ($hasProfile === false || $hasProfile === null) {
                $this->connection->insert('client_profiles', [
                    'client_id' => $clientId,
                    'company_name' => 'Apex Fleet Partner',
                ]);
            }

            // Reassign any dispatch requests that were linked to an engineer or admin to the client account
            $this->connection->executeStatement(<<<'SQL'
                UPDATE dispatch_requests d
                JOIN users u ON d.client_id = u.user_id
                SET d.client_id = ?
                WHERE u.role <> 'client'
                SQL, [$clientId]);

            // Reassign any invoices that were linked to an engineer or admin to the client account
            $this->connection->executeStatement(<<<'SQL'
                UPDATE invoices i
                JOIN users u ON i.client_id = u.user_id
                SET i.client_id = ?
                WHERE u.role <> 'client'
                SQL, [$clientId]);

            // Clean up any stray client_profiles rows that belonged to engineers or admins
            $this->connection->executeStatement(<<<'SQL'
                DELETE cp FROM client_profiles cp
                JOIN users u ON cp.client_id = u.user_id
                WHERE u.role <> 'client'
                SQL);
        } catch (\Throwable $exception) {
            $this->logger->warning('Unable to sanitize non-client dispatch assignments.', [
                'exception' => $exception,
            ]);
        }
    }

    private function positiveId(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }
}
