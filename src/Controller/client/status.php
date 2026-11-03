<?php

declare(strict_types=1);

namespace App\Controller\client;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class status extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/client/status', name: 'client_status', methods: ['GET'])]
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

        $requests = [];
        $updates = [];
        $loadError = false;

        try {
            $requests = $this->connection->fetchAllAssociative(<<<'SQL'
                SELECT
                    d.request_id,
                    d.vessel_name,
                    d.status,
                    d.requested_at,
                    i.invoice_id,
                    i.payment_status,
                    i.amount,
                    i.currency
                FROM dispatch_requests d
                LEFT JOIN invoices i ON d.request_id = i.request_id
                WHERE d.client_id = ?
                ORDER BY d.requested_at DESC
                SQL, [$clientId]);

            if ($requests !== []) {
                $updates = $this->connection->fetchAllAssociative(<<<'SQL'
                    SELECT
                        ou.request_id,
                        ou.status_milestone,
                        ou.detailed_message,
                        ou.logged_at
                    FROM operation_updates ou
                    JOIN dispatch_requests d ON ou.request_id = d.request_id
                    WHERE d.client_id = ?
                    ORDER BY ou.logged_at DESC
                    SQL, [$clientId]);
            }
        } catch (DBALException $exception) {
            $this->logger->error('Unable to retrieve client dispatch status and timeline.', [
                'client_id' => $clientId,
                'exception' => $exception,
            ]);
            $requests = [];
            $updates = [];
            $loadError = true;
        }

        $updatesByRequest = [];
        foreach ($updates as $update) {
            $updatesByRequest[$update['request_id']][] = $update;
        }

        return $this->render('client/status/index.html.twig', [
            'client_name' => $session->get('full_name', 'Client'),
            'requests' => $requests,
            'updates_by_request' => $updatesByRequest,
            'load_error' => $loadError,
        ]);
    }
}