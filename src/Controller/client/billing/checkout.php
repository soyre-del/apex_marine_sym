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

        if ($clientId === false || $session->get('role') !== 'client') {
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
                SELECT i.amount, i.currency, d.vessel_name, d.request_id
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
        $token = $form['csrf_token'] ?? null;

        if (!is_string($token) || !$this->isCsrfTokenValid('client_checkout', $token)) {
            return new Response('Invalid security token. Reload checkout and try again.', Response::HTTP_FORBIDDEN);
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
            return new Response('Invalid payment details.', Response::HTTP_BAD_REQUEST);
        }

        $referenceNumber = trim($reference) !== ''
            ? trim($reference)
            : 'TXN-'.strtoupper(bin2hex(random_bytes(12)));

        try {
            $updated = $this->connection->executeStatement(
                "UPDATE invoices
                SET payment_status = 'paid', payment_method = ?, reference_number = ?
                WHERE invoice_id = ? AND client_id = ? AND payment_status = 'unpaid'",
                [$paymentMethod, $referenceNumber, $invoiceId, $clientId],
            );
        } catch (DBALException $exception) {
            $this->logger->error('Unable to process simulated checkout payment.', [
                'client_id' => $clientId,
                'invoice_id' => $invoiceId,
                'exception' => $exception,
            ]);

            return new Response('Unable to complete payment. Please try again later.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        if ($updated !== 1) {
            return new Response('Invoice not found or already paid.', Response::HTTP_CONFLICT);
        }

        return $this->redirectToRoute(
            'client_payment_success',
            ['ref' => $referenceNumber],
            Response::HTTP_SEE_OTHER,
        );
    }
}