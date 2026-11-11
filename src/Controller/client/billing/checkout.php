<?php

declare(strict_types=1);

namespace App\Controller\client\billing;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class checkout extends AbstractController
{
    private const PAYMENT_METHODS = [
        'Bank Transfer',
        'Credit Card',
        'GCash',
        'Cash',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/client/checkout', name: 'client_checkout', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $session = $request->getSession();
        $identity = $session->get('user_id');
        $clientId = is_int($identity) || is_string($identity)
            ? filter_var($identity, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;

        $user = $this->getUser();
        if ($user instanceof \App\Entity\User) {
            $clientId = $user->getUserId();
            $session->set('user_id', $clientId);
            $session->set('role', $user->getRole());
            $session->set('full_name', $user->getFullName() ?? $user->getUserIdentifier());
        }

        $role = $session->get('role') ?? ($user instanceof \App\Entity\User ? $user->getRole() : null);
        if ($clientId === false || !in_array($role, ['client', 'admin'], true)) {
            throw $this->createAccessDeniedException('Client access is required.');
        }

        if ($request->isMethod('POST')) {
            return $this->submitPayment($request, $clientId);
        }

        $invoiceId = filter_var(
            $request->query->get('invoice_id'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        if ($invoiceId === false) {
            throw $this->createNotFoundException('Invoice not found or already paid.');
        }

        try {
            $invoice = $this->connection->fetchAssociative(<<<'SQL'
                SELECT i.invoice_id, i.amount, i.currency, i.client_id, d.vessel_name, d.request_id
                FROM invoices i
                JOIN dispatch_requests d ON i.request_id = d.request_id
                WHERE i.invoice_id = ?
                    AND i.client_id = ?
                    AND i.payment_status = 'unpaid'
                SQL, [$invoiceId, $clientId]);
        } catch (DBALException $exception) {
            $this->logger->error('Unable to retrieve an invoice for checkout.', [
                'client_id' => $clientId,
                'invoice_id' => $invoiceId,
                'exception' => $exception,
            ]);

            return new Response('Checkout is temporarily unavailable. Please try again later.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        if ($invoice === false) {
            throw $this->createNotFoundException('Invoice not found or already paid.');
        }

        return $this->render('client/billing/checkout.html.twig', [
            'invoice_id' => $invoiceId,
            'invoice' => $invoice,
        ]);
    }

    private function submitPayment(Request $request, int $clientId): Response
    {
        $form = $request->request->all();
        $token = $form['csrf_token'] ?? $form['_token'] ?? $form['_csrf_token'] ?? null;

        if (!is_string($token) || !$this->isCsrfTokenValid('client_checkout', $token)) {
            $this->addFlash('danger', 'Invalid security token. Please try submitting your payment again.');

            return $this->redirectToRoute('client_billing');
        }

        $invoiceId = filter_var(
            $form['invoice_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        $paymentMethod = $form['payment_method'] ?? null;
        $reference = $form['reference_number'] ?? '';

        if ($invoiceId === false
            || !is_string($paymentMethod)
            || !in_array($paymentMethod, self::PAYMENT_METHODS, true)
            || !is_string($reference)
            || strlen(trim($reference)) > 100) {
            $this->addFlash('danger', 'Please provide a valid payment method and reference number.');

            return $this->redirectToRoute('client_billing');
        }

        $referenceNumber = trim($reference) !== ''
            ? trim($reference)
            : 'TXN-'.strtoupper(bin2hex(random_bytes(6)));

        try {
            $updated = $this->connection->transactional(function (Connection $connection) use (
                $invoiceId,
                $clientId,
                $paymentMethod,
                $referenceNumber,
            ): int {
                $invoiceRow = $connection->fetchAssociative(
                    "SELECT request_id, amount, currency FROM invoices WHERE invoice_id = ? AND client_id = ? AND payment_status = 'unpaid' LIMIT 1",
                    [$invoiceId, $clientId],
                );
                if ($invoiceRow === false) {
                    return 0;
                }

                // Automatically mark the invoice as PAID upon client payment submission
                $affected = $connection->executeStatement(
                    "UPDATE invoices
                    SET payment_status = 'paid', payment_method = ?, reference_number = ?
                    WHERE invoice_id = ? AND client_id = ? AND payment_status = 'unpaid'",
                    [$paymentMethod, $referenceNumber, $invoiceId, $clientId],
                );

                if ($affected === 1) {
                    $connection->insert('operation_updates', [
                        'request_id' => (int) $invoiceRow['request_id'],
                        'updater_id' => $clientId,
                        'status_milestone' => 'Invoice Paid by Client',
                        'detailed_message' => sprintf(
                            'Client completed payment of %s %s via %s (Reference: %s). Invoice INV-%05d has been automatically marked as PAID.',
                            (string) $invoiceRow['currency'],
                            number_format((float) $invoiceRow['amount'], 2, '.', ','),
                            $paymentMethod,
                            $referenceNumber,
                            $invoiceId,
                        ),
                    ]);
                }

                return $affected;
            });
        } catch (DBALException $exception) {
            $this->logger->error('Unable to process checkout payment.', [
                'client_id' => $clientId,
                'invoice_id' => $invoiceId,
                'exception' => $exception,
            ]);
            $this->addFlash('danger', 'Unable to complete payment. Please try again later.');

            return $this->redirectToRoute('client_billing');
        }

        if ($updated !== 1) {
            $this->addFlash('warning', 'Invoice was not found or has already been marked as paid.');

            return $this->redirectToRoute('client_billing');
        }

        $this->addFlash('success', sprintf(
            'Payment received. Invoice INV-%05d has been automatically marked as PAID.',
            $invoiceId,
        ));

        return $this->redirectToRoute(
            'client_payment_success',
            ['ref' => $referenceNumber],
            Response::HTTP_SEE_OTHER,
        );
    }
}
