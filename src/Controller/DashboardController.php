<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'app_dashboard', methods: ['GET'])]
    #[Route('/dashboard/{section}', name: 'app_dashboard_section', requirements: ['section' => 'home|overview|about|service|contact|vessels|crew|voyages|maintenance|reports'], methods: ['GET'])]
    public function index(string $section = 'home'): RedirectResponse
    {
        $route = match ($section) {
            'about', 'crew' => 'app_about',
            'service', 'vessels', 'voyages', 'maintenance', 'reports' => 'app_service',
            'contact' => 'app_contact',
            default => 'app_home',
        };

        return $this->redirectToRoute($route);
    }
}
