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

        $user = $this->getUser();
        if ($user instanceof \App\Entity\User) {
            $clientId = $user->getUserId();
            $session->set('user_id', $clientId);
            $session->set('role', $user->getRole());
            $session->set('username', $user->getUsername() ?: ($user->getFirstName() ?? $user->getUserIdentifier()));
            $session->set('first_name', $user->getFirstName() ?? $user->getUserIdentifier());
            $session->set('full_name', $user->getFullName() ?? $user->getUserIdentifier());
        }

        $role = $session->get('role') ?? ($user instanceof \App\Entity\User ? $user->getRole() : null);
        if ($clientId === false || !in_array($role, ['client', 'admin'], true)) {
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
            'client_first_name' => $user instanceof \App\Entity\User ? ($user->getUsername() ?: ($user->getFirstName() ?: $user->getFullName())) : $session->get('username', $session->get('first_name', $session->get('full_name', 'Client'))),
            'invoices' => $invoices,
            'unpaid_count' => $unpaidCount,
            'invoices_load_error' => $invoicesLoadError,
        ]);
    }
}