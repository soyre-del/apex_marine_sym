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

final class active_ops extends AbstractController
{
    private const STATUSES = [
        'pending',
        'acknowledged',
        'deployed',
        'in_progress',
        'resolved',
        'cancelled',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/admin/active-operations', name: 'admin_active_operations', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $session = $request->getSession();
        $sessionIdentity = $session->get('user_id');
        $adminId = is_int($sessionIdentity) || is_string($sessionIdentity)
            ? filter_var($sessionIdentity, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
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

        if ($request->isMethod('POST')) {
            $action = $request->request->get('action');
            if ($action === 'clear_history') {
                return $this->clearHistory($request);
            }
            if ($action === 'delete_record') {
                return $this->deleteRecord($request);
            }

            return $this->postUpdate($request, $adminId);
        }

        $operations = [];
        $allRecords = [];
        $operationsLoadError = false;

        try {
            $operations = $this->connection->fetchAllAssociative(<<<'SQL'
                SELECT
                    d.request_id,
                    d.vessel_name,
                    d.vessel_type,
                    d.imo_number,
                    d.company,
                    d.service_type,
                    d.description,
                    d.status,
                    d.is_urgent,
                    d.requested_at,
                    dep.deployment_status,
                    dep.deployed_at,
                    TRIM(CONCAT_WS(' ', NULLIF(e.first_name, ''), NULLIF(e.middle_name, ''), NULLIF(e.surname, ''))) AS eng_name,
                    ep.engineer_id,
                    ep.specialty
                FROM dispatch_requests d
                JOIN deployments dep ON d.request_id = dep.request_id
                JOIN users e ON dep.engineer_id = e.user_id
                LEFT JOIN engineer_profiles ep ON e.user_id = ep.engineer_id
                WHERE dep.deployment_status != 'completed'
                AND d.status NOT IN ('resolved', 'cancelled')
                ORDER BY d.is_urgent DESC, d.requested_at DESC
                SQL);

            $allRecords = $this->connection->fetchAllAssociative(<<<'SQL'
                SELECT
                    d.request_id,
                    d.vessel_name,
                    d.vessel_type,
                    d.imo_number,
                    d.company,
                    d.contact_person,
                    d.contact_phone,
                    d.service_type,
                    d.description,
                    d.status,
                    d.is_urgent,
                    d.requested_at,
                    d.eta_date,
                    TRIM(CONCAT_WS(' ', NULLIF(c.first_name, ''), NULLIF(c.middle_name, ''), NULLIF(c.surname, ''))) AS client_name,
                    c.email AS client_email,
                    dep.deployment_id,
                    dep.deployment_status,
                    dep.deployed_at,
                    dep.engineer_id,
                    TRIM(CONCAT_WS(' ', NULLIF(e.first_name, ''), NULLIF(e.middle_name, ''), NULLIF(e.surname, ''))) AS eng_name,
                    ep.specialty,
                    l.port_name,
                    h.hub_name,
                    (
                        SELECT ou.status_milestone
                        FROM operation_updates ou
                        WHERE ou.request_id = d.request_id
                        ORDER BY ou.logged_at DESC
                        LIMIT 1
                    ) AS latest_milestone,
                    (
                        SELECT ou.detailed_message
                        FROM operation_updates ou
                        WHERE ou.request_id = d.request_id
                        ORDER BY ou.logged_at DESC
                        LIMIT 1
                    ) AS latest_message
                FROM dispatch_requests d
                LEFT JOIN users c ON d.client_id = c.user_id
                LEFT JOIN deployments dep ON d.request_id = dep.request_id
                LEFT JOIN users e ON dep.engineer_id = e.user_id
                LEFT JOIN engineer_profiles ep ON e.user_id = ep.engineer_id
                LEFT JOIN service_locations l ON d.location_id = l.location_id
                LEFT JOIN operational_hubs h ON l.hub_id = h.hub_id
                ORDER BY d.is_urgent DESC, d.requested_at DESC
                SQL);
        } catch (DBALException $exception) {
            $this->logger->error('Unable to fetch active operations.', ['exception' => $exception]);
            $operationsLoadError = true;
        }

        return $this->render('admin/active_operations/index.html.twig', [
            'operations' => $operations,
            'all_records' => $allRecords,
            'statuses' => self::STATUSES,
            'operations_load_error' => $operationsLoadError,
        ]);
    }

    private function postUpdate(Request $request, int $adminId): Response
    {
        $form = $request->request->all();
        if (($form['action'] ?? null) !== 'add_update') {
            return $this->redirectToRoute('admin_active_operations');
        }

        $requestId = filter_var(
            $form['request_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        $engineerId = filter_var(
            $form['engineer_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        $milestone = $form['milestone'] ?? null;
        $message = $form['detailed_message'] ?? null;
        $status = $form['new_status'] ?? null;
        $token = $form['_token'] ?? $form['csrf_token'] ?? $form['_csrf_token'] ?? null;

        if ($requestId === false
            || !is_string($milestone)
            || trim($milestone) === ''
            || !is_string($message)
            || trim($message) === ''
            || !is_string($status)
            || !in_array($status, self::STATUSES, true)) {
            $this->addFlash('danger', 'Enter a valid request, milestone, message, and status.');

            return $this->redirectToRoute('admin_active_operations');
        }

        if (!is_string($token) || !$this->isCsrfTokenValid('active_operation_'.$requestId, $token)) {
            $this->addFlash('danger', 'Security validation failed. Please try again.');

            return $this->redirectToRoute('admin_active_operations');
        }

        $deploymentStatus = match ($status) {
            'deployed' => 'en_route',
            'in_progress' => 'on_site',
            'resolved', 'cancelled' => 'completed',
            default => 'en_route',
        };

        try {
            $this->connection->transactional(function (Connection $connection) use (
                $requestId,
                $engineerId,
                $adminId,
                $milestone,
                $message,
                $status,
                $deploymentStatus,
            ): void {
                $connection->insert('operation_updates', [
                    'request_id' => $requestId,
                    'updater_id' => $adminId,
                    'status_milestone' => trim($milestone),
                    'detailed_message' => trim($message),
                ]);

                $connection->update('dispatch_requests', [
                    'status' => $status,
                ], [
                    'request_id' => $requestId,
                ]);

                if ($engineerId !== false && $engineerId > 0) {
                    $connection->update('deployments', [
                        'deployment_status' => $deploymentStatus,
                    ], [
                        'request_id' => $requestId,
                        'engineer_id' => $engineerId,
                    ]);

                    if (in_array($status, ['resolved', 'cancelled'], true)) {
                        $remainingActive = (int) $connection->fetchOne(
                            "SELECT COUNT(*) FROM deployments WHERE engineer_id = ? AND deployment_status <> 'completed'",
                            [$engineerId],
                        );
                        if ($remainingActive === 0) {
                            $connection->update('engineer_profiles', [
                                'current_status' => 'available',
                            ], [
                                'engineer_id' => $engineerId,
                            ]);
                        }
                    }
                }
            });
        } catch (DBALException $exception) {
            $this->logger->error('Unable to save an active operations update.', [
                'request_id' => $requestId,
                'exception' => $exception,
            ]);
            $this->addFlash('danger', 'Database error: unable to save the operational update.');

            return $this->redirectToRoute('admin_active_operations');
        }

        if (in_array($status, ['resolved', 'cancelled'], true)) {
            $this->addFlash('success', sprintf(
                'Mission Accomplished: Request #%d %s. Engineer freed.',
                $requestId,
                $status,
            ));
        } else {
            $this->addFlash('success', sprintf('Timeline Updated for Request #%d.', $requestId));
        }

        return $this->redirectToRoute('admin_active_operations');
    }

    private function clearHistory(Request $request): Response
    {
        $token = $request->request->get('csrf_token') ?? $request->request->get('_token');
        if (!is_string($token) || !$this->isCsrfTokenValid('admin_clear_history', $token)) {
            $this->addFlash('danger', 'Security validation failed. Unauthorized request.');

            return $this->redirectToRoute('admin_active_operations');
        }

        try {
            $this->connection->transactional(function (Connection $connection): void {
                $connection->executeStatement('DELETE FROM operation_updates');
                $connection->executeStatement('DELETE FROM invoices');
                $connection->executeStatement('DELETE FROM deployments');
                $connection->executeStatement('DELETE FROM dispatch_requests');
                $connection->executeStatement("UPDATE engineer_profiles SET current_status = 'available' WHERE current_status = 'deployed'");
            });
            $this->addFlash('success', 'All operational records and dispatch history have been successfully cleared.');
        } catch (\Throwable $exception) {
            $this->logger->error('Unable to clear operation history.', ['exception' => $exception]);
            $this->addFlash('danger', 'System error: Unable to clear records history.');
        }

        return $this->redirectToRoute('admin_active_operations');
    }

    private function deleteRecord(Request $request): Response
    {
        $requestId = filter_var($request->request->get('request_id'), FILTER_VALIDATE_INT);
        $token = $request->request->get('csrf_token') ?? $request->request->get('_token');
        if ($requestId === false || !is_string($token) || !$this->isCsrfTokenValid('delete_record_'.$requestId, $token)) {
            $this->addFlash('danger', 'Security validation failed. Unauthorized request.');

            return $this->redirectToRoute('admin_active_operations');
        }

        try {
            $this->connection->transactional(function (Connection $connection) use ($requestId): void {
                $engineerId = (int) $connection->fetchOne(
                    'SELECT engineer_id FROM deployments WHERE request_id = ?',
                    [$requestId],
                );

                $connection->executeStatement('DELETE FROM operation_updates WHERE request_id = ?', [$requestId]);
                $connection->executeStatement('DELETE FROM invoices WHERE request_id = ?', [$requestId]);
                $connection->executeStatement('DELETE FROM deployments WHERE request_id = ?', [$requestId]);
                $connection->executeStatement('DELETE FROM dispatch_requests WHERE request_id = ?', [$requestId]);

                if ($engineerId > 0) {
                    $remaining = (int) $connection->fetchOne(
                        "SELECT COUNT(*) FROM deployments WHERE engineer_id = ? AND deployment_status <> 'completed'",
                        [$engineerId],
                    );
                    if ($remaining === 0) {
                        $connection->executeStatement(
                            "UPDATE engineer_profiles SET current_status = 'available' WHERE engineer_id = ?",
                            [$engineerId],
                        );
                    }
                }
            });
            $this->addFlash('success', sprintf('Record REQ-%04d has been permanently deleted.', $requestId));
        } catch (\Throwable $exception) {
            $this->logger->error('Unable to delete dispatch record.', ['request_id' => $requestId, 'exception' => $exception]);
            $this->addFlash('danger', 'System error: Failed to delete record.');
        }

        return $this->redirectToRoute('admin_active_operations');
    }
}