<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Security\LoginAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserPasswordHasherInterface $userPasswordHasher,
        Security $security,
        EntityManagerInterface $entityManager,
    ): Response {
        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('plainPassword')->getData();
            if (!is_string($plainPassword)) {
                throw new \LogicException('The validated registration form must contain a password.');
            }

            if ($user->getEmail() !== null) {
                $user->setEmail(\App\Security\CredentialSecurityPolicy::normalizeEmail($user->getEmail()));
            }
            $user->setPassword($userPasswordHasher->hashPassword($user, $plainPassword));

            $entityManager->persist($user);
            $entityManager->flush();

            // Automatically provision a ClientProfile record if the registered account is a client
            if ($user->getRole() === 'client') {
                $clientProfile = new \App\Entity\ClientProfile();
                $clientProfile->setId($user->getUserId());
                $clientProfile->setCompanyName('Apex Fleet Partner');
                $entityManager->persist($clientProfile);
                $entityManager->flush();
            }

            // Synchronize session attributes for immediate authorization across portals
            $session = $request->getSession();
            $session->set('user_id', $user->getUserId());
            $session->set('role', $user->getRole());
            $session->set('username', $user->getUsername() ?: ($user->getFirstName() ?? $user->getUserIdentifier()));
            $session->set('first_name', $user->getFirstName() ?? $user->getUserIdentifier());
            $session->set('full_name', $user->getFullName() ?? $user->getUserIdentifier());

            $response = $security->login($user, LoginAuthenticator::class, 'main');

            return $response ?? $this->redirectToRoute('app_dashboard');
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }
}
