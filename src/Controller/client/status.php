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

    #[Route('/client/status', name: 'client_status', methods: ['GET', 'POST'])]
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

        // Handle Client CRUD POST actions
        if ($request->isMethod('POST')) {
            $action = $request->request->getString('action');
            $csrfToken = $request->request->getString('csrf_token') ?: ($request->request->getString('_token') ?: $request->request->getString('_csrf_token'));

            if ($action === 'cancel_request') {
                if (!$this->isCsrfTokenValid('client_cancel_request', $csrfToken)) {
                    $this->addFlash('danger', 'Security validation failed. Reload the page and try again.');
                } else {
                    $requestId = filter_var($request->request->get('request_id'), FILTER_VALIDATE_INT);
                    if ($requestId) {
                        try {
                            $affected = $this->connection->executeStatement(
                                "UPDATE dispatch_requests SET status = 'cancelled' WHERE request_id = ? AND client_id = ? AND status = 'pending'",
                                [$requestId, $clientId]
                            );
                            if ($affected > 0) {
                                $this->addFlash('success', 'Request cancelled successfully.');
                            } else {
                                $this->addFlash('warning', 'Only pending requests may be cancelled.');
                            }
                        } catch (\Throwable $e) {
                            $this->addFlash('danger', 'Unable to cancel request: '.$e->getMessage());
                        }
                    }
                }
                return $this->redirectToRoute('client_status', [], Response::HTTP_SEE_OTHER);
            }

            if ($action === 'edit_request') {
                if (!$this->isCsrfTokenValid('client_edit_request', $csrfToken)) {
                    $this->addFlash('danger', 'Security validation failed. Reload the page and try again.');
                } else {
                    $requestId = filter_var($request->request->get('request_id'), FILTER_VALIDATE_INT);
                    $description = trim($request->request->getString('description'));
                    $contactPerson = trim($request->request->getString('contact_person'));
                    $contactPhone = trim($request->request->getString('contact_phone'));

                    if ($requestId && $description !== '' && $contactPerson !== '') {
                        try {
                            $affected = $this->connection->executeStatement(
                                "UPDATE dispatch_requests SET description = ?, contact_person = ?, contact_phone = ? WHERE request_id = ? AND client_id = ? AND status = 'pending'",
                                [$description, $contactPerson, $contactPhone, $requestId, $clientId]
                            );
                            if ($affected > 0) {
                                $this->addFlash('success', 'Request updated successfully.');
                            } else {
                                $this->addFlash('warning', 'Only pending requests may be updated.');
                            }
                        } catch (\Throwable $e) {
                            $this->addFlash('danger', 'Unable to update request: '.$e->getMessage());
                        }
                    } else {
                        $this->addFlash('danger', 'Please provide a valid description and contact person.');
                    }
                }
                return $this->redirectToRoute('client_status', [], Response::HTTP_SEE_OTHER);
            }
        }

        $requests = [];
        $updates = [];
        $loadError = false;

        try {
            $requests = $this->connection->fetchAllAssociative(<<<'SQL'
                SELECT
                    d.request_id,
                    d.vessel_name,
                    d.vessel_type,
                    d.service_type,
                    d.contact_person,
                    d.contact_phone,
                    d.description,
                    d.is_urgent,
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
            'client_first_name' => $user instanceof \App\Entity\User ? ($user->getUsername() ?: ($user->getFirstName() ?: $user->getFullName())) : $session->get('username', $session->get('first_name', $session->get('full_name', 'Client'))),
            'requests' => $requests,
            'updates_by_request' => $updatesByRequest,
            'load_error' => $loadError,
        ]);
    }
}