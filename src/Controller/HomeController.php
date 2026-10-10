<?php

namespace App\Controller;

use App\Service\PageConfiguration;
use App\Service\PageImages;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    public function __construct(
        private readonly PageConfiguration $pageConfiguration,
        private readonly PageImages $pageImages,
    ) {
    }

    #[Route('/', name: 'app_home', methods: ['GET'])]
    #[Route('/home', name: 'app_home_alias', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('pages/home.html.twig', [
            'configuration' => $this->pageConfiguration->build('home'),
            'services' => $this->buildHomeServices(),
        ]);
    }

    #[Route('/api/dashboard/config', name: 'api_dashboard_config', methods: ['GET'])]
    public function configuration(): JsonResponse
    {
        return $this->json($this->pageConfiguration->build());
    }

    private function buildHomeServices(): array
    {
        return [
            [
                'title' => 'Marine Engineering',
                'description' => 'Expert technical diagnostics, complex system troubleshooting, and customized engineering interventions.',
                'image' => $this->pageImages->resolve('solutions.jpg'),
                'style' => 'engineering',
            ],
            [
                'title' => 'Preventative Maintenance',
                'description' => 'Proactive, scheduled inspection and maintenance engineered to extend the operational lifecycle of your vessel.',
                'image' => $this->pageImages->resolve('maintenancee.jpg'),
                'style' => 'maintenance',
            ],
            [
                'title' => 'Seamless Operation',
                'description' => 'Modern workflows, rapid response times, and efficient logistics. We streamline every project phase.',
                'image' => $this->pageImages->resolve('workflowww.jpg'),
                'style' => 'operation',
            ],
        ];
    }
}
