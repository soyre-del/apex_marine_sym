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

final class payment_success extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/client/payment-success', name: 'client_payment_success', methods: ['GET'])]
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

        $reference = $request->query->get('ref');
        if (!is_string($reference) || $reference === '' || strlen($reference) > 100) {
            return $this->redirectToRoute('client_billing');
        }

        try {
            $receipt = $this->connection->fetchAssociative(
                "SELECT reference_number
                FROM invoices
                WHERE client_id = ? AND reference_number = ? AND payment_status = 'paid'
                LIMIT 1",
                [$clientId, $reference],
            );
        } catch (DBALException $exception) {
            $this->logger->error('Unable to retrieve a client payment receipt.', [
                'client_id' => $clientId,
                'exception' => $exception,
            ]);

            return new Response('Receipt is temporarily unavailable. Please try again later.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        if ($receipt === false) {
            throw $this->createNotFoundException('Payment receipt not found.');
        }

        $referenceNumber = (string) $receipt['reference_number'];
        $formattedReference = is_numeric($referenceNumber)
            ? 'APX-TXN-'.str_pad($referenceNumber, 6, '0', STR_PAD_LEFT)
            : $referenceNumber;

        return $this->render('client/billing/payment_success.html.twig', [
            'formatted_reference' => $formattedReference,
            'receipt_generated_at' => new \DateTimeImmutable(),
        ]);
    }
}