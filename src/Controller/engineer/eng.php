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

        if ($engineerId === false || $session->get('role') !== 'engineer') {
            throw $this->createAccessDeniedException('Engineer access is required.');
        }

        if ($request->isMethod('POST')) {
            return $this->updateTask($request, $engineerId);
        }

        $tasks = [];
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
        } catch (DBALException $exception) {
            $this->logger->error('Unable to retrieve engineer assignments.', [
                'engineer_id' => $engineerId,
                'exception' => $exception,
            ]);
            $loadError = true;
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

        return $this->render('engineer/operations/index.html.twig', [
            'configuration' => $configuration,
            'engineer_name' => $session->get('full_name', 'Engineer'),
            'tasks' => $tasks,
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
                    'status_milestone' => trim($milestone),
                    'detailed_message' => trim($details),
                ]);

                if ($status === 'resolved') {
                    $connection->update('engineer_profiles', [
                        'current_status' => 'available',
                    ], [
                        'engineer_id' => $engineerId,
                    ]);

                    $connection->update('deployments', [
                        'deployment_status' => 'completed',
                    ], [
                        'request_id' => $requestId,
                        'engineer_id' => $engineerId,
                    ]);
                } else {
                    $connection->update('deployments', [
                        'deployment_status' => 'active',
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

        $this->addFlash('success', 'Operational log updated successfully.');

        return $this->redirectToRoute('engineer_operations', status: Response::HTTP_SEE_OTHER);
    }
}