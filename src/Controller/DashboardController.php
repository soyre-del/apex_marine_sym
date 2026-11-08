<?php

namespace App\Controller;

use App\Service\PageConfiguration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly PageConfiguration $pageConfiguration,
    ) {
    }

    #[Route('/dashboard', name: 'app_dashboard', methods: ['GET'])]
    #[Route('/dashboard/{section}', name: 'app_dashboard_section', requirements: ['section' => 'home|overview|about|service|contact|vessels|crew|voyages|maintenance|reports'], methods: ['GET'])]
    public function index(?string $section = null): Response
    {
        if ($section === null) {
            return $this->render('dashboard/home.html.twig', [
                'configuration' => $this->pageConfiguration->build('dashboard'),
            ]);
        }

        $route = match ($section) {
            'about', 'crew' => 'app_about',
            'service', 'vessels', 'voyages', 'maintenance', 'reports' => 'app_service',
            'contact' => 'app_contact',
            default => 'app_dashboard',
        };

        return $this->redirectToRoute($route);
    }
}
