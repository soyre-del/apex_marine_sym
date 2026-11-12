<?php

declare(strict_types=1);

namespace App\Controller\engineer;

use App\Service\PageConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class eng extends AbstractController
{
    private const STATUSES = [
        'deployed',
        'acknowledged',
        'pending',
        'in_progress',
        'resolved',
        'cancelled',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly PageConfiguration $pageConfiguration,
    ) {
    }

    #[Route('/engineer', name: 'engineer_operations', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $session = $request->getSession();
        $identity = $session->get('user_id');
        $engineerId = is_int($identity) || is_string($identity)
            ? filter_var($identity, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;

        $user = $this->getUser();
        if ($user instanceof \App\Entity\User) {
            $engineerId = $user->getUserId();
            $session->set('user_id', $engineerId);
            $session->set('role', $user->getRole());
            $session->set('full_name', $user->getFullName() ?? $user->getUserIdentifier());
        }

        $role = $session->get('role') ?? ($user instanceof \App\Entity\User ? $user->getRole() : null);
        if ($engineerId === false || !in_array($role, ['engineer', 'admin'], true)) {
            throw $this->createAccessDeniedException('Engineer access is required.');
        }

        if ($request->isMethod('POST')) {
            $action = $request->request->get('action');
            if ($action === 'create_record' || $action === 'create_field_report') {
                return $this->createRecord($request, $engineerId);
            }
            if ($action === 'edit_own_update') {
                return $this->editOwnUpdate($request, $engineerId);
            }
            if ($action === 'delete_own_update') {
                return $this->deleteOwnUpdate($request, $engineerId);
            }

            return $this->updateTask($request, $engineerId);
        }

        $tasks = [];
        $myUpdates = [];
        $loadError = false;
        try {
            $tasks = $this->connection->fetchAllAssociative(<<<'SQL'
                SELECT
                    dep.deployment_id,
                    dep.deployment_status,
                    r.request_id,
                    r.vessel_name,
                    r.company,
                    r.description,
                    r.status,
                    r.requested_at
                FROM deployments dep
                JOIN dispatch_requests r ON dep.request_id = r.request_id
                WHERE dep.engineer_id = ?
                    AND dep.deployment_status <> 'completed'
                ORDER BY r.requested_at DESC
                SQL, [$engineerId]);

            $myUpdates = $this->connection->fetchAllAssociative(<<<'SQL'
                SELECT
                    ou.update_id,
                    ou.request_id,
                    ou.status_milestone,
                    ou.detailed_message,
                    ou.logged_at,
                    r.vessel_name
                FROM operation_updates ou
                JOIN dispatch_requests r ON ou.request_id = r.request_id
                WHERE ou.updater_id = ?
                ORDER BY ou.logged_at DESC
                LIMIT 8
                SQL, [$engineerId]);

            $specialty = (string) $this->connection->fetchOne(
                'SELECT specialty FROM engineer_profiles WHERE engineer_id = ?',
                [$engineerId]
            );
        } catch (DBALException $exception) {
            $this->logger->error('Unable to retrieve engineer assignments.', [
                'engineer_id' => $engineerId,
                'exception' => $exception,
            ]);
            $loadError = true;
            $specialty = '';
        }

        $pendingTasks = count(array_filter(
            $tasks,
            static fn (array $task): bool => $task['status'] === 'deployed',
        ));
        $inProgressTasks = count(array_filter(
            $tasks,
            static fn (array $task): bool => $task['status'] === 'in_progress',
        ));

        $configuration = $this->pageConfiguration->build('home');
        foreach ($configuration['sidebar']['items'] as &$item) {
            $item['current'] = false;
        }
        unset($item);
        $configuration['sidebar']['items'][] = [
            'label' => 'Field Operations',
            'href' => $this->generateUrl('engineer_operations'),
            'current' => true,
        ];
        $configuration['sidebar']['items'][] = [
            'label' => 'My Profile',
            'href' => $this->generateUrl('app_profile'),
            'current' => false,
        ];

        return $this->render('engineer/operations/index.html.twig', [
            'configuration' => $configuration,
            'engineer_name' => $session->get('full_name', 'Engineer'),
            'engineer_specialty' => $specialty,
            'tasks' => $tasks,
            'my_updates' => $myUpdates,
            'total_tasks' => count($tasks),
            'pending_tasks' => $pendingTasks,
            'in_progress_tasks' => $inProgressTasks,
            'load_error' => $loadError,
        ]);
    }

    private function updateTask(Request $request, int $engineerId): Response
    {
        $form = $request->request->all();
        if (($form['action'] ?? null) !== 'update_task_status') {
            return $this->redirectToRoute('engineer_operations');
        }

        $token = $form['csrf_token'] ?? null;
        if (!is_string($token) || !$this->isCsrfTokenValid('engineer_task_update', $token)) {
            $this->addFlash('danger', 'Security token validation failed.');

            return $this->redirectToRoute('engineer_operations', status: Response::HTTP_SEE_OTHER);
        }

        $requestId = filter_var(
            $form['request_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        $status = $form['new_status'] ?? null;
        $milestone = $form['milestone'] ?? null;
        $details = $form['details'] ?? null;

        if ($requestId === false
            || !is_string($status)
            || !in_array($status, self::STATUSES, true)
            || !is_string($milestone)
            || trim($milestone) === ''
            || !is_string($details)
            || trim($details) === '') {
            $this->addFlash('warning', 'All log fields are required to update operations.');

            return $this->redirectToRoute('engineer_operations', status: Response::HTTP_SEE_OTHER);
        }

        try {
            $this->connection->transactional(function (Connection $connection) use (
                $requestId,
                $engineerId,
                $status,
                $milestone,
                $details,
            ): void {
                $deployment = $connection->fetchAssociative(
                    "SELECT deployment_id
                    FROM deployments
                    WHERE request_id = ? AND engineer_id = ? AND deployment_status <> 'completed'",
                    [$requestId, $engineerId],
                );
                if ($deployment === false) {
                    throw new \DomainException('This task is not assigned to you or is already completed.');
                }

                $connection->update('dispatch_requests', [
                    'status' => $status,
                ], [
                    'request_id' => $requestId,
                ]);

                $connection->insert('operation_updates', [
                    'request_id' => $requestId,
                    'updater_id' => $engineerId,
                    'status_milestone' => trim($milestone),
                    'detailed_message' => trim($details),
                ]);

                if (in_array($status, ['resolved', 'cancelled'], true)) {
                    $connection->update('deployments', [
                        'deployment_status' => 'completed',
                    ], [
                        'request_id' => $requestId,
                        'engineer_id' => $engineerId,
                    ]);

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
                } else {
                    $deploymentStatus = $status === 'in_progress' ? 'on_site' : 'en_route';
                    $connection->update('deployments', [
                        'deployment_status' => $deploymentStatus,
                    ], [
                        'request_id' => $requestId,
                        'engineer_id' => $engineerId,
                    ]);
                }
            });
        } catch (\DomainException $exception) {
            $this->addFlash('warning', $exception->getMessage());

            return $this->redirectToRoute('engineer_operations', status: Response::HTTP_SEE_OTHER);
        } catch (DBALException $exception) {
            $this->logger->error('Unable to save engineer task update.', [
                'request_id' => $requestId,
                'engineer_id' => $engineerId,
                'exception' => $exception,
            ]);
            $this->addFlash('danger', 'CRITICAL FAULT: Unable to log update.');

            return $this->redirectToRoute('engineer_operations', status: Response::HTTP_SEE_OTHER);
        }

        $this->addFlash('success', 'Log saved successfully.');

        return $this->redirectToRoute('engineer_operations', status: Response::HTTP_SEE_OTHER);
    }

    private function editOwnUpdate(Request $request, int $engineerId): Response
    {
        $form = $request->request->all();
        $token = $form['csrf_token'] ?? null;
        if (!is_string($token) || !$this->isCsrfTokenValid('engineer_edit_update', $token)) {
            $this->addFlash('danger', 'Security validation failed.');

            return $this->redirectToRoute('engineer_operations', status: Response::HTTP_SEE_OTHER);
        }

        $updateId = filter_var($form['update_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $milestone = trim((string) ($form['milestone'] ?? ''));
        $details = trim((string) ($form['details'] ?? ''));

        if ($updateId === false || $milestone === '' || $details === '') {
            $this->addFlash('warning', 'Please provide a valid milestone and detailed message.');

            return $this->redirectToRoute('engineer_operations', status: Response::HTTP_SEE_OTHER);
        }

        try {
            $affected = $this->connection->update('operation_updates', [
                'status_milestone' => $milestone,
                'detailed_message' => $details,
            ], [
                'update_id' => $updateId,
                'updater_id' => $engineerId,
            ]);

            if ($affected > 0) {
                $this->addFlash('success', 'Log updated successfully.');
            } else {
                $this->addFlash('warning', 'Log entry not found or unauthorized.');
            }
        } catch (DBALException $exception) {
            $this->logger->error('Unable to update operational log entry.', ['exception' => $exception]);
            $this->addFlash('danger', 'System error while updating log.');
        }

        return $this->redirectToRoute('engineer_operations', status: Response::HTTP_SEE_OTHER);
    }

    private function deleteOwnUpdate(Request $request, int $engineerId): Response
    {
        $form = $request->request->all();
        $token = $form['csrf_token'] ?? null;
        if (!is_string($token) || !$this->isCsrfTokenValid('engineer_delete_update', $token)) {
            $this->addFlash('danger', 'Security validation failed.');

            return $this->redirectToRoute('engineer_operations', status: Response::HTTP_SEE_OTHER);
        }

        $updateId = filter_var($form['update_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($updateId === false) {
            $this->addFlash('warning', 'Invalid log entry ID.');

            return $this->redirectToRoute('engineer_operations', status: Response::HTTP_SEE_OTHER);
        }

        try {
            $affected = $this->connection->delete('operation_updates', [
                'update_id' => $updateId,
                'updater_id' => $engineerId,
            ]);

            if ($affected > 0) {
                $this->addFlash('success', 'Log retracted successfully.');
            } else {
                $this->addFlash('warning', 'Log entry not found or unauthorized.');
            }
        } catch (\Throwable $e) {
            $this->logger->error('Unable to delete operational log entry.', [
                'update_id' => $updateId,
                'engineer_id' => $engineerId,
                'exception' => $e,
            ]);
            $this->addFlash('danger', 'CRITICAL FAULT: Unable to retract log entry.');
        }

        return $this->redirectToRoute('engineer_operations', status: Response::HTTP_SEE_OTHER);
    }

    private function createRecord(Request $request, int $engineerId): Response
    {
        $form = $request->request->all();
        $token = $form['csrf_token'] ?? null;
        if (!is_string($token) || !$this->isCsrfTokenValid('engineer_create_record', $token)) {
            $this->addFlash('danger', 'Security validation failed.');

            return $this->redirectToRoute('engineer_operations', status: Response::HTTP_SEE_OTHER);
        }

        $vesselName = trim((string) ($form['vessel_name'] ?? ''));
        $serviceType = trim((string) ($form['service_type'] ?? ''));
        $milestone = trim((string) ($form['milestone'] ?? ''));
        $details = trim((string) ($form['details'] ?? ''));
        $status = (string) ($form['status'] ?? 'in_progress');

        if (!in_array($status, ['deployed', 'in_progress', 'resolved'], true)) {
            $status = 'in_progress';
        }

        if ($vesselName === '' || $serviceType === '' || $milestone === '' || $details === '') {
            $this->addFlash('warning', 'All fields (Vessel Name, Service Type, Milestone, Details) are required.');

            return $this->redirectToRoute('engineer_operations', status: Response::HTTP_SEE_OTHER);
        }

        try {
            $this->connection->transactional(function (Connection $conn) use (
                $engineerId,
                $vesselName,
                $serviceType,
                $milestone,
                $details,
                $status
            ): void {
                $clientId = (int) $conn->fetchOne("SELECT user_id FROM users WHERE role = 'client' AND is_active = 1 ORDER BY user_id ASC LIMIT 1");
                if ($clientId <= 0) {
                    $clientId = 1;
                }

                $locationId = (int) $conn->fetchOne("SELECT location_id FROM service_locations ORDER BY location_id ASC LIMIT 1");
                if ($locationId <= 0) {
                    $locationId = 1;
                }

                $adminId = (int) $conn->fetchOne("SELECT user_id FROM users WHERE role = 'admin' ORDER BY user_id ASC LIMIT 1");
                if ($adminId <= 0) {
                    $adminId = 3;
                }

                $conn->insert('dispatch_requests', [
                    'vessel_name' => $vesselName,
                    'imo_number' => 'IMO-' . random_int(9100000, 9999999),
                    'vessel_type' => 'Commercial Carrier',
                    'contact_person' => 'Chief Engineer / Field Operative',
                    'contact_phone' => '+1 (555) 019-2831',
                    'service_type' => $serviceType,
                    'is_urgent' => 0,
                    'description' => $details,
                    'client_id' => $clientId,
                    'company' => 'Apex Marine Client Services',
                    'location_id' => $locationId,
                    'status' => $status,
                    'is_active' => 1,
                    'eta_date' => (new \DateTimeImmutable())->format('Y-m-d'),
                ]);

                $requestId = (int) $conn->lastInsertId();

                $depStatus = match ($status) {
                    'resolved' => 'completed',
                    'in_progress' => 'on_site',
                    default => 'en_route',
                };

                $conn->insert('deployments', [
                    'deployment_status' => $depStatus,
                    'request_id' => $requestId,
                    'admin_id' => $adminId,
                    'engineer_id' => $engineerId,
                ]);

                $conn->insert('operation_updates', [
                    'request_id' => $requestId,
                    'updater_id' => $engineerId,
                    'status_milestone' => $milestone,
                    'detailed_message' => $details,
                ]);

                $engProfileStatus = ($status === 'resolved') ? 'available' : 'deployed';
                $conn->update('engineer_profiles', [
                    'current_status' => $engProfileStatus,
                ], [
                    'engineer_id' => $engineerId,
                ]);
            });

            $this->addFlash('success', 'Field service record created successfully for ' . $vesselName . '.');
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create field service record.', [
                'engineer_id' => $engineerId,
                'error' => $e->getMessage(),
            ]);
            $this->addFlash('danger', 'CRITICAL FAULT: Unable to record service log.');
        }

        return $this->redirectToRoute('engineer_operations', status: Response::HTTP_SEE_OTHER);
    }
}