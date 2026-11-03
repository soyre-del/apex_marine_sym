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
use Symfony\Component\HttpFoundation\RequestStack;

final class admin extends AbstractController
{
    private const ALLOWED_SPECIALTIES = [
        'Chief Engineer',
        'Propulsion & Machinery Specialist',
        'Electrical & Automation Engineer',
        'Hydraulics & Deck Gear Technician',
        'Hull & Steel Fabricator',
        'Preventative Maintenance Expert',
        'General Marine Consultant',
    ];

    private const ALLOWED_STATUSES = ['available', 'deployed', 'on_leave'];

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly RequestStack $requestStack,
    ) {
    }

    #[Route('/admin', name: 'admin_dashboard', methods: ['GET', 'POST'])]
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
            return $this->processAction($request);
        }

        $systemMessage = null;
        $messageType = null;
        foreach (['success', 'warning', 'danger'] as $type) {
            $messages = $session->getFlashBag()->get($type);
            if ($messages !== []) {
                $systemMessage = (string) end($messages);
                $messageType = $type;
            }
        }

        $oldPost = $session->remove('admin_old_post') ?? [];
        $failedAction = $session->remove('admin_failed_action') ?? '';
        $metrics = [
            'pending_dispatches' => 'ERR',
            'total_clients' => 'ERR',
            'available_engineers' => 'ERR',
            'deployed_engineers' => 'ERR',
        ];
        $engineers = [];
        $dashboardLoadError = false;

        try {
            try {
                $metrics['pending_dispatches'] = $this->connection->fetchOne(
                    "SELECT COUNT(*) FROM dispatch_requests WHERE status = 'pending' AND is_active = 1"
                );
            } catch (DBALException $exception) {
                $this->logger->error('Unable to count pending dispatches.', ['exception' => $exception]);
                $metrics['pending_dispatches'] = 0;
            }

            $metrics['total_clients'] = $this->connection->fetchOne(
                "SELECT COUNT(*) FROM users WHERE role = 'client' AND is_active = 1"
            );

            $engineerStats = $this->connection->fetchAssociative(<<<'SQL'
                SELECT
                    SUM(CASE WHEN ep.current_status = 'available' THEN 1 ELSE 0 END) AS available,
                    SUM(CASE WHEN ep.current_status = 'deployed' THEN 1 ELSE 0 END) AS deployed
                FROM engineer_profiles ep
                JOIN users u ON ep.engineer_id = u.user_id
                WHERE u.is_active = 1
                SQL);
            $metrics['available_engineers'] = $engineerStats['available'] ?? 0;
            $metrics['deployed_engineers'] = $engineerStats['deployed'] ?? 0;

            $engineers = $this->connection->fetchAllAssociative(<<<'SQL'
                SELECT u.user_id, u.full_name, u.email, u.is_active, ep.specialty, ep.current_status
                FROM users u
                JOIN engineer_profiles ep ON u.user_id = ep.engineer_id
                WHERE u.role = 'engineer'
                ORDER BY u.is_active DESC, ep.current_status ASC, u.full_name ASC
                SQL);
        } catch (DBALException $exception) {
            $this->logger->error('Unable to retrieve admin dashboard data.', ['exception' => $exception]);
            $metrics = [
                'pending_dispatches' => 'ERR',
                'total_clients' => 'ERR',
                'available_engineers' => 'ERR',
                'deployed_engineers' => 'ERR',
            ];
            $engineers = [];
            $dashboardLoadError = true;
        }

        return $this->render('admin/dashboard.html.twig', [
            'admin_name' => $session->get('full_name', 'Admin'),
            'specialties' => self::ALLOWED_SPECIALTIES,
            'statuses' => self::ALLOWED_STATUSES,
            'metrics' => $metrics,
            'engineers' => $engineers,
            'system_message' => $systemMessage,
            'message_type' => $messageType,
            'failed_action' => $failedAction,
            'old_post' => is_array($oldPost) ? $oldPost : [],
            'dashboard_load_error' => $dashboardLoadError,
        ]);
    }

    private function processAction(Request $request): Response
    {
        $form = $request->request->all();
        $action = $form['action'] ?? null;
        $token = $form['csrf_token'] ?? null;

        if (!is_string($action) || !in_array($action, [
            'add_engineer',
            'edit_engineer',
            'delete_engineer',
            'restore_engineer',
        ], true)) {
            return $this->redirectToRoute('admin_dashboard');
        }

        if (!is_string($token) || !$this->isCsrfTokenValid('admin_engineers', $token)) {
            $this->addFlash('danger', 'Security token validation failed. Unauthorized request.');

            return $this->redirectToRoute('admin_dashboard');
        }

        return match ($action) {
            'add_engineer' => $this->addEngineer($form),
            'edit_engineer' => $this->editEngineer($form),
            'delete_engineer' => $this->setEngineerActive($form, false),
            'restore_engineer' => $this->setEngineerActive($form, true),
        };
    }

    private function addEngineer(array $form): Response
    {
        $name = $this->stringField($form, 'eng_name');
        $email = $this->stringField($form, 'eng_email');
        $password = $form['eng_password'] ?? '';
        $specialty = $this->stringField($form, 'eng_specialty');
        $status = $this->stringField($form, 'eng_status', 'available');
        $status = in_array($status, self::ALLOWED_STATUSES, true) ? $status : 'available';
        $specialty = in_array($specialty, self::ALLOWED_SPECIALTIES, true) ? $specialty : '';

        if ($name === '' || $email === '' || !is_string($password) || $password === '' || $specialty === '') {
            return $this->formFailure(
                'All personnel fields are required for deployment. Please select a valid discipline.',
                'warning',
                'add_engineer',
                $form,
            );
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->formFailure('Invalid email format.', 'warning', 'add_engineer', $form);
        }
        if (strlen($password) < 8) {
            return $this->formFailure('Access Code must be at least 8 characters.', 'warning', 'add_engineer', $form);
        }

        try {
            if ($this->connection->fetchOne('SELECT user_id FROM users WHERE email = ? LIMIT 1', [$email])) {
                return $this->formFailure(
                    'Registration Halted: Engineer email already exists in the matrix.',
                    'danger',
                    'add_engineer',
                    $form,
                );
            }

            $this->connection->transactional(function (Connection $connection) use ($name, $email, $password, $specialty, $status): void {
                $connection->insert('users', [
                    'full_name' => $name,
                    'email' => $email,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'role' => 'engineer',
                    'is_active' => 1,
                ]);
                $newId = $connection->lastInsertId();
                if ($newId === false) {
                    throw new \RuntimeException('Unable to determine the new engineer ID.');
                }

                $connection->insert('engineer_profiles', [
                    'engineer_id' => (int) $newId,
                    'specialty' => $specialty,
                    'current_status' => $status,
                ]);
            });
        } catch (DBALException|\RuntimeException $exception) {
            $this->logger->error('Unable to deploy an engineer.', ['exception' => $exception]);

            return $this->formFailure(
                'CRITICAL ERROR: Unable to deploy engineer due to a system fault.',
                'danger',
                'add_engineer',
                $form,
            );
        }

        $this->addFlash('success', sprintf('Success: Engineer %s has been officially deployed to the roster.', $name));

        return $this->redirectToRoute('admin_dashboard');
    }

    private function editEngineer(array $form): Response
    {
        $id = $this->positiveId($form['user_id'] ?? null);
        $name = $this->stringField($form, 'eng_name');
        $email = $this->stringField($form, 'eng_email');
        $password = $form['eng_password'] ?? '';
        $specialty = $this->stringField($form, 'eng_specialty');
        $status = $this->stringField($form, 'eng_status', 'available');
        $status = in_array($status, self::ALLOWED_STATUSES, true) ? $status : 'available';
        $specialty = in_array($specialty, self::ALLOWED_SPECIALTIES, true) ? $specialty : '';

        if ($id === null || $name === '' || $email === '' || $specialty === '') {
            return $this->formFailure('Critical fields missing for profile update.', 'warning', 'edit_engineer', $form);
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->formFailure('Invalid email format provided.', 'warning', 'edit_engineer', $form);
        }
        if (!is_string($password) || ($password !== '' && strlen($password) < 8)) {
            return $this->formFailure('New Access Code must be at least 8 characters.', 'warning', 'edit_engineer', $form);
        }

        try {
            if ($this->connection->fetchOne(
                'SELECT user_id FROM users WHERE email = ? AND user_id != ? LIMIT 1',
                [$email, $id],
            )) {
                return $this->formFailure(
                    'Update Halted: Email is already registered to another operative.',
                    'danger',
                    'edit_engineer',
                    $form,
                );
            }

            $this->connection->transactional(function (Connection $connection) use (
                $id,
                $name,
                $email,
                $password,
                $specialty,
                $status,
            ): void {
                $userData = [
                    'full_name' => $name,
                    'email' => $email,
                ];
                if ($password !== '') {
                    $userData['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                }
                $connection->update('users', $userData, [
                    'user_id' => $id,
                    'role' => 'engineer',
                ]);
                $connection->update('engineer_profiles', [
                    'specialty' => $specialty,
                    'current_status' => $status,
                ], [
                    'engineer_id' => $id,
                ]);
            });
        } catch (DBALException $exception) {
            $this->logger->error('Unable to update an engineer profile.', [
                'user_id' => $id,
                'exception' => $exception,
            ]);

            return $this->formFailure('CRITICAL ERROR: Unable to update profile.', 'danger', 'edit_engineer', $form);
        }

        $this->addFlash('success', sprintf('Success: Engineer profile for %s updated successfully.', $name));

        return $this->redirectToRoute('admin_dashboard');
    }

    private function setEngineerActive(array $form, bool $active): Response
    {
        $id = $this->positiveId($form['user_id'] ?? null);
        if ($id === null) {
            return $this->redirectToRoute('admin_dashboard');
        }

        try {
            $this->connection->transactional(function (Connection $connection) use ($id, $active): void {
                $connection->update('users', [
                    'is_active' => $active ? 1 : 0,
                ], [
                    'user_id' => $id,
                    'role' => 'engineer',
                ]);
                $connection->update('engineer_profiles', [
                    'current_status' => $active ? 'available' : 'on_leave',
                ], [
                    'engineer_id' => $id,
                ]);
            });
        } catch (DBALException $exception) {
            $this->logger->error($active ? 'Unable to restore an engineer.' : 'Unable to deactivate an engineer.', [
                'user_id' => $id,
                'exception' => $exception,
            ]);
            $this->addFlash('danger', $active
                ? 'CRITICAL ERROR: Unable to restore profile.'
                : 'CRITICAL ERROR: Unable to deactivate profile.');

            return $this->redirectToRoute('admin_dashboard');
        }

        $this->addFlash('success', $active
            ? 'Operative access restored. Engineer is now available for deployment.'
            : 'Operative access revoked and profile deactivated.');

        return $this->redirectToRoute('admin_dashboard');
    }

    private function formFailure(string $message, string $type, string $action, array $form): Response
    {
        $this->addFlash($type, $message);
        unset($form['eng_password'], $form['csrf_token']);
        $session = $this->requestStack->getSession();
        $session->set('admin_old_post', $form);
        $session->set('admin_failed_action', $action);

        return $this->redirectToRoute('admin_dashboard');
    }

    private function stringField(array $form, string $key, string $default = ''): string
    {
        $value = $form[$key] ?? $default;

        return is_string($value) ? trim($value) : '';
    }

    private function positiveId(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }
}