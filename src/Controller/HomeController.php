<?php

namespace App\Controller;

use App\Service\PageConfiguration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    public function __construct(private readonly PageConfiguration $pageConfiguration)
    {
    }

    #[Route('/', name: 'app_home', methods: ['GET'])]
    #[Route('/home', name: 'app_home_alias', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $request->getSession()->start();

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
        $services = [
            [
                'title' => 'Marine Engineering',
                'description' => 'Expert technical diagnostics, complex system troubleshooting, and customized engineering interventions.',
                'image' => 'assets/images/solutions.jpg',
                'style' => 'engineering',
            ],
            [
                'title' => 'Preventative Maintenance',
                'description' => 'Proactive, scheduled inspection and maintenance engineered to extend the operational lifecycle of your vessel.',
                'image' => 'assets/images/maintenancee.jpg',
                'style' => 'maintenance',
            ],
            [
                'title' => 'Seamless Operation',
                'description' => 'Modern workflows, rapid response times, and efficient logistics. We streamline every project phase.',
                'image' => 'assets/images/workflowww.jpg',
                'style' => 'operation',
            ],
        ];

        $publicDirectory = $this->getParameter('kernel.project_dir') . '/public/';

        foreach ($services as $index => $service) {
            // Display a placeholder until the original photo is supplied.
            if (!is_file($publicDirectory . $service['image'])) {
                $services[$index]['image'] = null;
            }
        }

        return $services;
    }

}
