<?php

namespace App\Controller;

use App\Service\PageConfiguration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ServiceController extends AbstractController
{
    public function __construct(private readonly PageConfiguration $pageConfiguration)
    {
    }

    #[Route('/service', name: 'app_service', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $request->getSession()->start();

        return $this->render('pages/service.html.twig', [
            'configuration' => $this->pageConfiguration->build('service'),
            'service' => $this->buildServiceData(),
        ]);
    }

    #[Route('/services', name: 'app_services', methods: ['GET'])]
    public function services(): RedirectResponse
    {
        return $this->redirectToRoute('app_service');
    }

    private function buildServiceData(): array
    {
        $images = [
            'engineering' => 'assets/images/solutions.jpg',
            'maintenance' => 'assets/images/maintenancee.jpg',
            'operation' => 'assets/images/workflowww.jpg',
        ];
        $publicDirectory = $this->getParameter('kernel.project_dir') . '/public/';

        foreach ($images as $key => $image) {
            if (!is_file($publicDirectory . $image)) {
                $images[$key] = null;
            }
        }

        return [
            'images' => $images,
            'disciplines' => [
                [
                    'title' => 'Propulsion & Machinery',
                    'description' => '2-stroke/4-stroke main engine overhauls, turbocharger servicing, alignment, and heavy machinery reconditioning.',
                    'icon' => 'sliders',
                    'color' => 'ocean',
                ],
                [
                    'title' => 'Electrical & Automation',
                    'description' => 'PLC programming, main switchboard diagnostics, alarm monitoring systems, and thermographic surveys.',
                    'icon' => 'lightning',
                    'color' => 'caution',
                ],
                [
                    'title' => 'Hydraulics & Deck Gear',
                    'description' => 'Mooring winches, cargo cranes, steering gear overhaul, and high-pressure hose fabrication and testing.',
                    'icon' => 'toolbox',
                    'color' => 'white',
                ],
                [
                    'title' => 'Hull & Steel Fabrication',
                    'description' => 'Class-approved welding (ABS/DNV), steel plate renewal, pipe fabrication (CuNi, Stainless), and NDT testing.',
                    'icon' => 'cube',
                    'color' => 'ocean',
                ],
            ],
            'engineering_checks' => [
                'Crankshaft Deflection & Alignment',
                'Governor & Actuator Calibration',
                'Fuel Injection Timing & Overhaul',
                'Purifier & Separator Rebuilds',
            ],
            'maintenance_details' => [
                ['label' => 'Dry-Dock Preparation', 'description' => 'Comprehensive pre-docking inspections, definitive scope-of-work formulation, and parts pre-ordering logistics.'],
                ['label' => 'Lifecycle Management', 'description' => 'Scheduled megger testing, safety valve recertification, and heat exchanger chemical cleaning.'],
            ],
            'regions' => [
                ['label' => 'APAC Hub', 'description' => 'Singapore & Shanghai', 'note' => 'Rapid Deployment Zone'],
                ['label' => 'EMEA Hub', 'description' => 'Rotterdam, Dubai & Cape Town', 'note' => null],
                ['label' => 'Americas Hub', 'description' => 'Houston & Panama City', 'note' => null],
            ],
            'process' => [
                [
                    'title' => 'Diagnosis',
                    'description' => 'Remote assessment and root-cause analysis by our senior technical superintendents.',
                    'color' => 'ocean',
                ],
                [
                    'title' => 'Mobilization',
                    'description' => 'Dispatch of OEM-certified engineers, specialized tooling, and required spares via our global hubs.',
                    'color' => 'caution',
                ],
                [
                    'title' => 'Execution',
                    'description' => 'Precision repairs conducted dockside, at anchorage, or mid-voyage by our riding squads.',
                    'color' => 'white',
                ],
                [
                    'title' => 'Class Approval',
                    'description' => 'Sea trials, comprehensive reporting, and final sign-off by IACS classification societies.',
                    'color' => 'ocean',
                ],
            ],
        ];
    }
}
