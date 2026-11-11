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
            return $this->processAction($request, $adminId);
        }

        $pendingRequests = [];
        $availableEngineers = [];
        $loadError = false;

        try {
            // Ensure any dispatch_requests accidentally linked to a non-client user are reassigned to a client account
            $defaultClientId = $this->connection->fetchOne(
                "SELECT user_id FROM users WHERE role = 'client' ORDER BY is_active DESC, user_id ASC LIMIT 1"
            );
            if ($defaultClientId !== false && $defaultClientId !== null) {
                $cid = (int) $defaultClientId;
                $hasProfile = $this->connection->fetchOne('SELECT client_id FROM client_profiles WHERE client_id = ? LIMIT 1', [$cid]);
                if ($hasProfile === false || $hasProfile === null) {
                    $this->connection->insert('client_profiles', ['client_id' => $cid, 'company_name' => 'Apex Fleet Partner']);
                }
                $this->connection->executeStatement(<<<'SQL'
                    UPDATE dispatch_requests d
                    JOIN users u ON d.client_id = u.user_id
                    SET d.client_id = ?
                    WHERE u.role <> 'client'
                    SQL, [$cid]);
            }

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
                    TRIM(CONCAT_WS(' ', NULLIF(u.first_name, ''), NULLIF(u.middle_name, ''), NULLIF(u.surname, ''))) AS client_name,
                    u.email AS client_email,
                    l.port_name,
                    h.hub_name
                FROM dispatch_requests d
                LEFT JOIN users u ON d.client_id = u.user_id AND u.role = 'client'
                LEFT JOIN service_locations l ON d.location_id = l.location_id
                LEFT JOIN operational_hubs h ON l.hub_id = h.hub_id
                WHERE d.status IN ('pending', 'acknowledged') AND d.is_active = 1
                ORDER BY d.is_urgent DESC, d.requested_at ASC
                SQL);

            $availableEngineers = $this->connection->fetchAllAssociative(<<<'SQL'
                SELECT
                    u.user_id,
                    TRIM(CONCAT_WS(' ', NULLIF(u.first_name, ''), NULLIF(u.middle_name, ''), NULLIF(u.surname, ''))) AS full_name,
                    ep.specialty
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
                ORDER BY ep.specialty ASC, u.first_name ASC, u.surname ASC
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
            $matched = array_values(array_filter(
                $availableEngineers,
                fn (array $engineer): bool => $this->sameDiscipline(
                    (string) $dispatch['service_type'],
                    (string) $engineer['specialty'],
                ),
            ));

            // Prioritize Chief Engineers at the top of the deployment roster as universal problem solvers
            usort($matched, function (array $a, array $b): int {
                $isChiefA = str_contains(strtolower((string) $a['specialty']), 'chief engineer') ? 1 : 0;
                $isChiefB = str_contains(strtolower((string) $b['specialty']), 'chief engineer') ? 1 : 0;
                if ($isChiefA !== $isChiefB) {
                    return $isChiefB <=> $isChiefA;
                }
                return strcmp((string) $a['full_name'], (string) $b['full_name']);
            });

            $matchingEngineers[$dispatch['request_id']] = $matched;
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
        $token = $form['csrf_token'] ?? $form['_token'] ?? $form['_csrf_token'] ?? null;

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

        $engineer = null;

        try {
            $this->connection->transactional(function (Connection $connection) use (
                $requestId,
                $engineerId,
                $adminId,
                &$engineer,
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
                    throw new \DomainException('The selected engineer is not qualified for this discipline. Only the Chief Engineer possesses universal clearance across all problem domains.');
                }

                $validAdminId = $connection->fetchOne(
                    'SELECT user_id FROM users WHERE user_id = ? LIMIT 1',
                    [$adminId],
                );
                if ($validAdminId === false || $validAdminId === null) {
                    $validAdminId = $connection->fetchOne(
                        "SELECT user_id FROM users WHERE role = 'admin' ORDER BY user_id ASC LIMIT 1",
                    ) ?: $engineerId;
                }

                $existingDeploymentId = $connection->fetchOne(
                    'SELECT deployment_id FROM deployments WHERE request_id = ? LIMIT 1',
                    [$requestId],
                );

                if ($existingDeploymentId !== false && $existingDeploymentId !== null) {
                    $connection->executeStatement(
                        "UPDATE deployments
                        SET admin_id = ?, engineer_id = ?, deployment_status = 'en_route', deployed_at = CURRENT_TIMESTAMP
                        WHERE deployment_id = ?",
                        [(int) $validAdminId, $engineerId, (int) $existingDeploymentId],
                    );
                } else {
                    $connection->insert('deployments', [
                        'request_id' => $requestId,
                        'admin_id' => (int) $validAdminId,
                        'engineer_id' => $engineerId,
                        'deployment_status' => 'en_route',
                    ]);
                }

                $updatedRequest = $connection->executeStatement(
                    "UPDATE dispatch_requests
                    SET status = 'deployed'
                    WHERE request_id = ? AND status IN ('pending', 'acknowledged') AND is_active = 1",
                    [$requestId],
                );
                if ($updatedRequest !== 1) {
                    throw new \DomainException('This request is no longer awaiting deployment.');
                }

                $connection->executeStatement(
                    "UPDATE engineer_profiles
                    SET current_status = 'deployed'
                    WHERE engineer_id = ?",
                    [$engineerId],
                );
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

        $specialty = is_array($engineer) ? (string) ($engineer['specialty'] ?? '') : '';
        $isChief = str_contains(strtolower($specialty), 'chief engineer');
        $this->addFlash(
            'success',
            $isChief
                ? 'Chief Engineer deployed successfully.'
                : 'Engineer deployed successfully.',
        );

        return $this->redirectToRoute('admin_pending_dispatch');
    }

    private function updateTaskStatus(array $form): Response
    {
        $requestId = $this->positiveId($form['request_id'] ?? null);
        $status = $form['new_status'] ?? null;
        if ($requestId === null || !is_string($status) || !in_array($status, ['resolved', 'cancelled'], true)) {
            $this->addFlash('warning', 'Invalid task action.');

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
            $this->addFlash('danger', 'Unable to modify task status.');

            return $this->redirectToRoute('admin_pending_dispatch');
        }

        if ($updated !== 1) {
            $this->addFlash('warning', 'Task is no longer awaiting deployment.');

            return $this->redirectToRoute('admin_pending_dispatch');
        }

        $this->addFlash(
            $status === 'resolved' ? 'success' : 'warning',
            $status === 'resolved' ? 'Task marked as resolved.' : 'Task cancelled.',
        );

        return $this->redirectToRoute('admin_pending_dispatch');
    }

    private function sameDiscipline(string $requested, string $specialty): bool
    {
        $requested = strtolower(trim($requested));
        $specialty = strtolower(trim($specialty));

        // Chief Engineer is universally capable of fixing everything and deployable to any problem
        if (str_contains($specialty, 'chief engineer')) {
            return true;
        }

        // Exact match
        if ($requested === $specialty) {
            return true;
        }

        // Domain matching for other specialized technical roles
        $domainKeywords = [
            'propulsion' => ['propulsion', 'machinery', 'engine', 'shaft', 'diesel', 'turbine'],
            'electrical' => ['electrical', 'automation', 'electronics', 'plc', 'power', 'switchboard'],
            'hydraulic' => ['hydraulic', 'deck gear', 'deck equipment', 'winch', 'crane', 'steering'],
            'hull' => ['hull', 'steel', 'fabricat', 'welding', 'structural', 'plate'],
            'preventative' => ['preventative', 'maintenance', 'survey', 'overhaul', 'inspection'],
            'consult' => ['consult', 'superintendent', 'advice', 'assessment'],
        ];

        foreach ($domainKeywords as $terms) {
            $reqMatches = false;
            foreach ($terms as $term) {
                if (str_contains($requested, $term)) {
                    $reqMatches = true;
                    break;
                }
            }
            if ($reqMatches) {
                foreach ($terms as $term) {
                    if (str_contains($specialty, $term)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function positiveId(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }
}