<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Security\CredentialSecurityPolicy;
use App\Service\PageConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProfileController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly PageConfiguration $pageConfiguration,
    ) {
    }

    #[Route('/profile', name: 'app_profile', methods: ['GET', 'POST'])]
    #[Route('/client/profile', name: 'client_profile', methods: ['GET', 'POST'])]
    #[Route('/engineer/profile', name: 'engineer_profile', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $session = $request->getSession();
        $user = $this->getUser();

        $userId = null;
        $role = null;

        if ($user instanceof User) {
            $userId = $user->getUserId();
            $role = $user->getRole();
        } else {
            $sessionUserId = $session->get('user_id');
            $validId = is_int($sessionUserId) || is_string($sessionUserId)
                ? filter_var($sessionUserId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                : false;
            if ($validId !== false) {
                $userId = $validId;
                $role = (string) $session->get('role', 'client');
            }
        }

        if ($userId === null) {
            return $this->redirectToRoute('app_login');
        }

        if ($request->isMethod('POST')) {
            return $this->handleUpdate($request, $userId, (string) $role, $user instanceof User ? $user : null);
        }

        $userRow = $this->connection->fetchAssociative(
            'SELECT user_id, username, first_name, middle_name, surname, email, role, is_active, created_at FROM users WHERE user_id = ? LIMIT 1',
            [$userId],
        );

        if ($userRow === false) {
            throw $this->createNotFoundException('Account profile could not be found.');
        }

        $role = (string) ($userRow['role'] ?? $role ?? 'client');
        $clientProfile = null;
        $engineerProfile = null;
        $stats = [
            'total_ops' => 0,
            'active_ops' => 0,
        ];

        if ($role === 'client') {
            $clientProfile = $this->connection->fetchAssociative(
                'SELECT company_name, contact_number FROM client_profiles WHERE client_id = ? LIMIT 1',
                [$userId],
            ) ?: ['company_name' => 'Apex Fleet Partner', 'contact_number' => ''];

            $stats['total_ops'] = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM dispatch_requests WHERE client_id = ?',
                [$userId],
            );
            $stats['active_ops'] = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM dispatch_requests WHERE client_id = ? AND status IN ('pending', 'acknowledged', 'deployed', 'in_progress')",
                [$userId],
            );
        } elseif ($role === 'engineer') {
            $engineerProfile = $this->connection->fetchAssociative(
                'SELECT specialty, current_status FROM engineer_profiles WHERE engineer_id = ? LIMIT 1',
                [$userId],
            ) ?: ['specialty' => 'Unassigned', 'current_status' => 'available'];

            $stats['total_ops'] = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM deployments WHERE engineer_id = ?',
                [$userId],
            );
            $stats['active_ops'] = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM deployments WHERE engineer_id = ? AND deployment_status <> 'completed'",
                [$userId],
            );
        }

        $fullName = trim(implode(' ', array_filter([
            $userRow['first_name'] ?? '',
            $userRow['middle_name'] ?? '',
            $userRow['surname'] ?? '',
        ], static fn (mixed $v): bool => is_string($v) && trim($v) !== '')));

        $configuration = $this->pageConfiguration->build('dashboard');
        foreach ($configuration['sidebar']['items'] as &$item) {
            $item['current'] = false;
        }
        unset($item);

        if ($role === 'engineer') {
            $configuration['sidebar']['items'][] = [
                'label' => 'Field Operations',
                'href' => $this->generateUrl('engineer_operations'),
                'current' => false,
            ];
        }
        $configuration['sidebar']['items'][] = [
            'label' => 'My Profile',
            'href' => $this->generateUrl('app_profile'),
            'current' => true,
        ];

        return $this->render('profile/index.html.twig', [
            'configuration' => $configuration,
            'profile' => $userRow,
            'full_name' => $fullName,
            'role' => $role,
            'can_edit_username' => $role === 'client',
            'client_profile' => $clientProfile,
            'engineer_profile' => $engineerProfile,
            'stats' => $stats,
        ]);
    }

    private function handleUpdate(Request $request, int $userId, string $role, ?User $authenticatedUser): Response
    {
        $token = $request->request->getString('csrf_token');
        if (!$this->isCsrfTokenValid('profile_update', $token)) {
            $this->addFlash('danger', 'Security validation failed. Please try again.');

            return $this->redirectToRoute('app_profile');
        }

        $firstName = User::capitalizeName(trim($request->request->getString('first_name')));
        $middleRaw = User::capitalizeName(trim($request->request->getString('middle_name')));
        $middleName = $middleRaw !== '' ? $middleRaw : null;
        $surname = User::capitalizeName(trim($request->request->getString('surname')));
        $email = CredentialSecurityPolicy::normalizeEmail($request->request->getString('email'));
        $newPassword = $request->request->getString('new_password');

        if ($firstName === '' || strlen($firstName) < 2) {
            $this->addFlash('warning', 'First name is required and must be at least 2 characters.');

            return $this->redirectToRoute('app_profile');
        }

        if ($surname === '' || strlen($surname) < 2) {
            $this->addFlash('warning', 'Surname is required and must be at least 2 characters.');

            return $this->redirectToRoute('app_profile');
        }

        if (
            preg_match('/^[\p{L}\s\'.-]+$/u', $firstName) !== 1
            || ($middleName !== null && preg_match('/^[\p{L}\s\'.-]+$/u', $middleName) !== 1)
            || preg_match('/^[\p{L}\s\'.-]+$/u', $surname) !== 1
        ) {
            $this->addFlash('warning', 'Names may only contain letters, spaces, hyphens, and apostrophes.');

            return $this->redirectToRoute('app_profile');
        }

        $emailError = CredentialSecurityPolicy::validateEmail($email);
        if ($emailError !== null) {
            $this->addFlash('warning', $emailError);

            return $this->redirectToRoute('app_profile');
        }

        if ($this->connection->fetchOne('SELECT user_id FROM users WHERE email = ? AND user_id != ? LIMIT 1', [$email, $userId])) {
            $this->addFlash('danger', 'That email address is already registered to another account.');

            return $this->redirectToRoute('app_profile');
        }

        $updateData = [
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'surname' => $surname,
            'email' => $email,
        ];

        // Only client accounts are permitted to edit their profile username
        if ($role === 'client') {
            $username = trim($request->request->getString('username'));
            $usernameError = CredentialSecurityPolicy::validateUsername($username);
            if ($usernameError !== null) {
                $this->addFlash('warning', $usernameError);

                return $this->redirectToRoute('app_profile');
            }

            if ($this->connection->fetchOne('SELECT user_id FROM users WHERE username = ? AND user_id != ? LIMIT 1', [$username, $userId])) {
                $this->addFlash('danger', 'That username is already taken by another account.');

                return $this->redirectToRoute('app_profile');
            }

            $updateData['username'] = $username;
        }

        if ($newPassword !== '') {
            $passwordError = CredentialSecurityPolicy::validatePassword($newPassword, $email);
            if ($passwordError !== null) {
                $this->addFlash('warning', $passwordError);

                return $this->redirectToRoute('app_profile');
            }
            $updateData['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
        }

        try {
            $this->connection->transactional(function (Connection $connection) use ($userId, $role, $updateData, $request): void {
                $connection->update('users', $updateData, ['user_id' => $userId]);

                if ($role === 'client') {
                    $companyName = trim($request->request->getString('company_name'));
                    $contactNumber = trim($request->request->getString('contact_number'));

                    $hasClientProfile = $connection->fetchOne(
                        'SELECT client_id FROM client_profiles WHERE client_id = ? LIMIT 1',
                        [$userId],
                    );

                    if ($hasClientProfile !== false && $hasClientProfile !== null) {
                        $connection->update('client_profiles', [
                            'company_name' => $companyName !== '' ? $companyName : 'Apex Fleet Partner',
                            'contact_number' => $contactNumber !== '' ? $contactNumber : null,
                        ], [
                            'client_id' => $userId,
                        ]);
                    } else {
                        $connection->insert('client_profiles', [
                            'client_id' => $userId,
                            'company_name' => $companyName !== '' ? $companyName : 'Apex Fleet Partner',
                            'contact_number' => $contactNumber !== '' ? $contactNumber : null,
                        ]);
                    }
                }
            });

            if ($authenticatedUser instanceof User) {
                $authenticatedUser->setFirstName($firstName);
                $authenticatedUser->setMiddleName($middleName);
                $authenticatedUser->setSurname($surname);
                $authenticatedUser->setEmail($email);
                if (isset($updateData['username'])) {
                    $authenticatedUser->setUsername($updateData['username']);
                }
                if (isset($updateData['password_hash'])) {
                    $authenticatedUser->setPassword($updateData['password_hash']);
                }
                $this->entityManager->flush();
            }

            $session = $request->getSession();
            $fullName = trim(implode(' ', array_filter([$firstName, $middleName, $surname])));
            $session->set('first_name', $firstName);
            $session->set('full_name', $fullName);
            if (isset($updateData['username'])) {
                $session->set('username', $updateData['username']);
            }
        } catch (\Throwable) {
            $this->addFlash('danger', 'Unable to save profile changes right now. Please try again.');

            return $this->redirectToRoute('app_profile');
        }

        $this->addFlash('success', 'Your profile has been updated.');

        return $this->redirectToRoute('app_profile');
    }
}
