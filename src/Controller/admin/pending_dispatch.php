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

final class pending_dispatch extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/admin/pending-dispatch', name: 'admin_pending_dispatch', methods: ['GET', 'POST'])]
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
            return $this->processAction($request, $adminId);
        }

        $pendingRequests = [];
        $availableEngineers = [];
        $loadError = false;

        try {
            $pendingRequests = $this->connection->fetchAllAssociative(<<<'SQL'
                SELECT
                    d.request_id,
                    d.vessel_name,
                    d.imo_number,
                    d.vessel_type,
                    d.company,
                    d.contact_person,
                    d.contact_phone,
                    d.description,
                    d.service_type,
                    d.is_urgent,
                    d.eta_date,
                    d.requested_at,
                    d.attachment_path,
                    u.full_name AS client_name,
                    u.email AS client_email,
                    l.port_name,
                    h.hub_name
                FROM dispatch_requests d
                LEFT JOIN users u ON d.client_id = u.user_id
                LEFT JOIN service_locations l ON d.location_id = l.location_id
                LEFT JOIN operational_hubs h ON l.hub_id = h.hub_id
                WHERE d.status IN ('pending', 'acknowledged') AND d.is_active = 1
                ORDER BY d.is_urgent DESC, d.requested_at ASC
                SQL);

            $availableEngineers = $this->connection->fetchAllAssociative(<<<'SQL'
                SELECT u.user_id, u.full_name, ep.specialty
                FROM users u
                JOIN engineer_profiles ep ON u.user_id = ep.engineer_id
                WHERE ep.current_status = 'available'
                    AND u.is_active = 1
                    AND u.role = 'engineer'
                    AND NOT EXISTS (
                        SELECT 1
                        FROM deployments dep
                        WHERE dep.engineer_id = ep.engineer_id
                            AND dep.deployment_status <> 'completed'
                    )
                ORDER BY ep.specialty ASC, u.full_name ASC
                SQL);
        } catch (DBALException $exception) {
            $this->logger->error('Unable to retrieve pending dispatch assignments.', [
                'exception' => $exception,
            ]);
            $pendingRequests = [];
            $availableEngineers = [];
            $loadError = true;
        }

        $matchingEngineers = [];
        foreach ($pendingRequests as $dispatch) {
            $matchingEngineers[$dispatch['request_id']] = array_values(array_filter(
                $availableEngineers,
                fn (array $engineer): bool => $this->sameDiscipline(
                    (string) $dispatch['service_type'],
                    (string) $engineer['specialty'],
                ),
            ));
        }

        return $this->render('admin/pending_dispatch/index.html.twig', [
            'pending_requests' => $pendingRequests,
            'available_engineers' => $availableEngineers,
            'matching_engineers' => $matchingEngineers,
            'load_error' => $loadError,
        ]);
    }

    private function processAction(Request $request, int $adminId): Response
    {
        $form = $request->request->all();
        $action = $form['action'] ?? null;
        $token = $form['csrf_token'] ?? null;

        if (!is_string($action) || !in_array($action, ['assign_engineer', 'update_task_status'], true)) {
            return $this->redirectToRoute('admin_pending_dispatch');
        }

        if (!is_string($token) || !$this->isCsrfTokenValid('pending_dispatch', $token)) {
            $this->addFlash('danger', 'Security token validation failed.');

            return $this->redirectToRoute('admin_pending_dispatch');
        }

        return match ($action) {
            'assign_engineer' => $this->assignEngineer($form, $adminId),
            'update_task_status' => $this->updateTaskStatus($form),
        };
    }

    private function assignEngineer(array $form, int $adminId): Response
    {
        $requestId = $this->positiveId($form['request_id'] ?? null);
        $engineerId = $this->positiveId($form['engineer_id'] ?? null);
        if ($requestId === null || $engineerId === null) {
            $this->addFlash('warning', 'Invalid assignment parameters. Please select a valid engineer.');

            return $this->redirectToRoute('admin_pending_dispatch');
        }

        try {
            $this->connection->transactional(function (Connection $connection) use (
                $requestId,
                $engineerId,
            ): void {
                $dispatch = $connection->fetchAssociative(
                    "SELECT request_id, service_type
                    FROM dispatch_requests
                    WHERE request_id = ? AND status IN ('pending', 'acknowledged') AND is_active = 1",
                    [$requestId],
                );
                if ($dispatch === false) {
                    throw new \DomainException('This request is no longer awaiting deployment.');
                }

                $engineer = $connection->fetchAssociative(<<<'SQL'
                    SELECT u.user_id, ep.specialty
                    FROM users u
                    JOIN engineer_profiles ep ON u.user_id = ep.engineer_id
                    WHERE u.user_id = ?
                        AND u.role = 'engineer'
                        AND u.is_active = 1
                        AND ep.current_status = 'available'
                        AND NOT EXISTS (
                            SELECT 1
                            FROM deployments dep
                            WHERE dep.engineer_id = ep.engineer_id
                                AND dep.deployment_status <> 'completed'
                        )
                    SQL, [$engineerId]);
                if ($engineer === false) {
                    throw new \DomainException('The selected engineer is no longer available.');
                }

                if (!$this->sameDiscipline((string) $dispatch['service_type'], (string) $engineer['specialty'])) {
                    throw new \DomainException('The selected engineer does not match the requested discipline.');
                }

                $connection->insert('deployments', [
                    'request_id' => $requestId,
                    'engineer_id' => $engineerId,
                    'deployment_status' => 'en_route',
                ]);

                $updatedRequest = $connection->executeStatement(
                    "UPDATE dispatch_requests
                    SET status = 'deployed'
                    WHERE request_id = ? AND status IN ('pending', 'acknowledged') AND is_active = 1",
                    [$requestId],
                );
                if ($updatedRequest !== 1) {
                    throw new \DomainException('This request is no longer awaiting deployment.');
                }

                $updatedEngineer = $connection->update('engineer_profiles', [
                    'current_status' => 'deployed',
                ], [
                    'engineer_id' => $engineerId,
                    'current_status' => 'available',
                ]);
                if ($updatedEngineer !== 1) {
                    throw new \DomainException('The selected engineer is no longer available.');
                }
            });
        } catch (\DomainException $exception) {
            $this->addFlash('warning', $exception->getMessage());

            return $this->redirectToRoute('admin_pending_dispatch');
        } catch (DBALException $exception) {
            $this->logger->error('Unable to assign an engineer to a dispatch request.', [
                'request_id' => $requestId,
                'engineer_id' => $engineerId,
                'admin_id' => $adminId,
                'exception' => $exception,
            ]);
            $this->addFlash('danger', 'CRITICAL FAULT: Database rejected the deployment.');

            return $this->redirectToRoute('admin_pending_dispatch');
        }

        $this->addFlash(
            'success',
            sprintf('Deployment authorized: Matching specialist dispatched to Request #%d.', $requestId),
        );

        return $this->redirectToRoute('admin_pending_dispatch');
    }

    private function updateTaskStatus(array $form): Response
    {
        $requestId = $this->positiveId($form['request_id'] ?? null);
        $status = $form['new_status'] ?? null;
        if ($requestId === null || !is_string($status) || !in_array($status, ['resolved', 'cancelled'], true)) {
            $this->addFlash('warning', 'Invalid task status action.');

            return $this->redirectToRoute('admin_pending_dispatch');
        }

        try {
            $updated = $this->connection->executeStatement(
                "UPDATE dispatch_requests
                SET status = ?
                WHERE request_id = ? AND status IN ('pending', 'acknowledged') AND is_active = 1",
                [$status, $requestId],
            );
        } catch (DBALException $exception) {
            $this->logger->error('Unable to update pending dispatch status.', [
                'request_id' => $requestId,
                'exception' => $exception,
            ]);
            $this->addFlash('danger', 'CRITICAL FAULT: Unable to modify task status.');

            return $this->redirectToRoute('admin_pending_dispatch');
        }

        if ($updated !== 1) {
            $this->addFlash('warning', 'This task is no longer awaiting deployment.');

            return $this->redirectToRoute('admin_pending_dispatch');
        }

        $this->addFlash(
            $status === 'resolved' ? 'success' : 'warning',
            sprintf(
                'Task #%d has been successfully marked as %s.',
                $requestId,
                $status === 'resolved' ? 'Finished/Resolved' : 'Terminated/Cancelled',
            ),
        );

        return $this->redirectToRoute('admin_pending_dispatch');
    }

    private function sameDiscipline(string $requested, string $specialty): bool
    {
        return strtolower(trim($requested)) === strtolower(trim($specialty));
    }

    private function positiveId(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }
}