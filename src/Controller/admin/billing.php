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

        if ($adminId === false || $session->get('role') !== 'admin') {
            throw $this->createAccessDeniedException('Administrator access is required.');
        }

        if ($request->isMethod('POST')) {
            return $this->processAction($request);
        }

        $unbilledRequests = [];
        $invoices = [];
        $totalRevenue = 0.0;
        $pendingRevenue = 0.0;
        $unbilledLoadError = false;
        $invoicesLoadError = false;

        try {
            $unbilledRequests = $this->connection->fetchAllAssociative(<<<'SQL'
                SELECT
                    d.request_id,
                    d.vessel_name,
                    d.status,
                    u.full_name AS client_name,
                    (
                        SELECT status_milestone
                        FROM operation_updates
                        WHERE request_id = d.request_id
                        ORDER BY logged_at DESC
                        LIMIT 1
                    ) AS latest_milestone,
                    (
                        SELECT detailed_message
                        FROM operation_updates
                        WHERE request_id = d.request_id
                        ORDER BY logged_at DESC
                        LIMIT 1
                    ) AS problem_description
                FROM dispatch_requests d
                JOIN users u ON d.client_id = u.user_id
                LEFT JOIN invoices i ON d.request_id = i.request_id
                WHERE i.invoice_id IS NULL
                ORDER BY
                    CASE WHEN d.status = 'completed' THEN 1 ELSE 2 END,
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
                    u.full_name AS client_name,
                    u.email AS client_email,
                    d.request_id,
                    d.vessel_name
                FROM invoices i
                JOIN users u ON i.client_id = u.user_id
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
            'total_revenue' => $totalRevenue,
            'pending_revenue' => $pendingRevenue,
        ]);
    }

    private function processAction(Request $request): Response
    {
        $form = $request->request->all();
        $action = $form['action'] ?? null;

        if (!is_string($action) || !in_array($action, ['create_invoice', 'update_status'], true)) {
            return $this->redirectToRoute('admin_billing');
        }

        $token = $form['_token'] ?? null;
        if (!is_string($token) || !$this->isCsrfTokenValid('admin_billing', $token)) {
            $this->addFlash('danger', 'Security validation failed. Please try again.');

            return $this->redirectToRoute('admin_billing');
        }

        return match ($action) {
            'create_invoice' => $this->createInvoice($form),
            'update_status' => $this->markInvoicePaid($form),
        };
    }

    private function createInvoice(array $form): Response
    {
        $requestId = $this->positiveId($form['request_id'] ?? null);
        $amountInput = $form['amount'] ?? null;
        $currency = $form['currency'] ?? 'USD';

        if ($requestId === null
            || (!is_string($amountInput) && !is_int($amountInput) && !is_float($amountInput))
            || !is_numeric($amountInput)
            || !is_finite((float) $amountInput)
            || (float) $amountInput <= 0
            || !is_string($currency)
            || $currency !== 'USD') {
            $this->addFlash('warning', 'Enter a valid request, positive amount, and supported currency.');

            return $this->redirectToRoute('admin_billing');
        }

        try {
            $clientId = $this->connection->fetchOne(
                'SELECT client_id FROM dispatch_requests WHERE request_id = ?',
                [$requestId],
            );

            if ($clientId === false || $clientId === null) {
                $this->addFlash('danger', 'Dispatch request not found.');

                return $this->redirectToRoute('admin_billing');
            }

            $this->connection->insert('invoices', [
                'request_id' => $requestId,
                'client_id' => (int) $clientId,
                'amount' => (float) $amountInput,
                'currency' => $currency,
                'payment_status' => 'unpaid',
            ]);
        } catch (DBALException $exception) {
            $this->logger->error('Unable to create an invoice.', [
                'request_id' => $requestId,
                'exception' => $exception,
            ]);
            $this->addFlash('danger', 'Failed to create invoice. Please try again later.');

            return $this->redirectToRoute('admin_billing');
        }

        $this->addFlash('success', sprintf(
            'Invoice successfully generated for REQ-%04d',
            $requestId,
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
                'Invoice INV-%05d updated to PAID',
                $invoiceId,
            ));
        } else {
            $this->addFlash('danger', 'This invoice is already paid or could not be found.');
        }

        return $this->redirectToRoute('admin_billing');
    }

    private function positiveId(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }
}