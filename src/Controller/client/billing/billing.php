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

final class billing extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/client/billing', name: 'client_billing', methods: ['GET'])]
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

        $invoices = [];
        $unpaidCount = 0;
        $invoicesLoadError = false;

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
                    d.vessel_name,
                    d.request_id,
                    d.status AS operation_status
                FROM invoices i
                JOIN dispatch_requests d ON i.request_id = d.request_id
                WHERE i.client_id = ?
                ORDER BY i.payment_status DESC, i.created_at DESC
                SQL, [$clientId]);

            foreach ($invoices as $invoice) {
                if ($invoice['payment_status'] === 'unpaid') {
                    ++$unpaidCount;
                }
            }
        } catch (DBALException $exception) {
            $this->logger->error('Unable to retrieve client invoices.', [
                'client_id' => $clientId,
                'exception' => $exception,
            ]);
            $invoicesLoadError = true;
        }

        return $this->render('client/billing/index.html.twig', [
            'client_name' => $session->get('full_name', 'Client'),
            'invoices' => $invoices,
            'unpaid_count' => $unpaidCount,
            'invoices_load_error' => $invoicesLoadError,
        ]);
    }
}