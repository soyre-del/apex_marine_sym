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

        if ($adminId === false || $session->get('role') !== 'admin') {
            throw $this->createAccessDeniedException('Administrator access is required.');
        }

        if ($request->isMethod('POST')) {
            return $this->postUpdate($request, $adminId);
        }

        $operations = [];
        $operationsLoadError = false;

        try {
            $operations = $this->connection->fetchAllAssociative(<<<'SQL'
                SELECT
                    d.request_id, d.vessel_name, d.status, d.is_urgent,
                    e.full_name AS eng_name, ep.engineer_id
                FROM dispatch_requests d
                JOIN deployments dep ON d.request_id = dep.request_id
                JOIN users e ON dep.engineer_id = e.user_id
                JOIN engineer_profiles ep ON e.user_id = ep.engineer_id
                WHERE dep.deployment_status != 'completed'
                AND d.status NOT IN ('resolved', 'cancelled')
                GROUP BY d.request_id, dep.engineer_id
                ORDER BY d.is_urgent DESC, d.requested_at DESC
                SQL);
        } catch (DBALException $exception) {
            $this->logger->error('Unable to fetch active operations.', ['exception' => $exception]);
            $operationsLoadError = true;
        }

        return $this->render('admin/active_operations/index.html.twig', [
            'operations' => $operations,
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
        $token = $form['_token'] ?? null;

        if ($requestId === false
            || $engineerId === false
            || !is_string($milestone)
            || trim($milestone) === ''
            || !is_string($message)
            || trim($message) === ''
            || !is_string($status)
            || !in_array($status, self::STATUSES, true)) {
            $this->addFlash('danger', 'Enter a valid request, engineer, milestone, message, and status.');

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
            default => 'active',
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

                $connection->update('deployments', [
                    'deployment_status' => $deploymentStatus,
                ], [
                    'request_id' => $requestId,
                    'engineer_id' => $engineerId,
                ]);

                if (in_array($status, ['resolved', 'cancelled'], true)) {
                    $connection->update('engineer_profiles', [
                        'current_status' => 'available',
                    ], [
                        'engineer_id' => $engineerId,
                    ]);
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
}