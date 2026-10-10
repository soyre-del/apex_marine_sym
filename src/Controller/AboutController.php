<?php

namespace App\Controller;

use App\Service\PageConfiguration;
use App\Service\PageImages;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AboutController extends AbstractController
{
    public function __construct(
        private readonly PageConfiguration $pageConfiguration,
        private readonly PageImages $pageImages,
    ) {
    }

    #[Route('/about', name: 'app_about', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('pages/about.html.twig', [
            'configuration' => $this->pageConfiguration->build('about'),
            'about' => $this->buildAboutData(),
        ]);
    }

    private function buildAboutData(): array
    {
        return [
            'heritage_image' => $this->pageImages->resolve('founded.jpg'),
            'statistics' => [
                ['value' => '110', 'suffix' => '+', 'label' => 'Years Active'],
                ['value' => '24', 'suffix' => '/7', 'label' => 'Global Dispatch'],
                ['value' => '45', 'suffix' => '+', 'label' => 'Major Ports'],
                ['value' => '12', 'suffix' => 'k', 'label' => 'Vessels Serviced'],
            ],
            'regions' => [
                ['label' => 'APAC Hub', 'description' => 'Singapore & Shanghai (Rapid Deployment Zone)'],
                ['label' => 'EMEA Hub', 'description' => 'Rotterdam, Dubai & Cape Town'],
                ['label' => 'Americas Hub', 'description' => 'Houston & Panama City'],
            ],
            'hubs' => [
                ['city' => 'Singapore', 'region' => 'APAC Hub', 'description' => 'Rapid Deployment Zone', 'top' => 55, 'left' => 79, 'delay' => 0],
                ['city' => 'Shanghai', 'region' => 'APAC Hub', 'description' => 'Dry-Dock Facility', 'top' => 35, 'left' => 83, 'delay' => 0.3],
                ['city' => 'Rotterdam', 'region' => 'EMEA Hub', 'description' => 'Command Center', 'top' => 25, 'left' => 51, 'delay' => 0.6],
                ['city' => 'Dubai', 'region' => 'EMEA Hub', 'description' => 'Mechanical Overhaul', 'top' => 42, 'left' => 65, 'delay' => 0.9],
                ['city' => 'Cape Town', 'region' => 'EMEA Hub', 'description' => 'Emergency Dispatch', 'top' => 75, 'left' => 55, 'delay' => 1.2],
                ['city' => 'Houston', 'region' => 'Americas Hub', 'description' => 'Structural Repair', 'top' => 40, 'left' => 23, 'delay' => 1.5],
                ['city' => 'Panama City', 'region' => 'Americas Hub', 'description' => 'Mid-Voyage Interventions', 'top' => 52, 'left' => 28, 'delay' => 1.8],
            ],
            'competencies' => [
                [
                    'title' => 'Heavy-Duty Repairs',
                    'description' => 'Comprehensive structural and mechanical overhauls engineered to restore main propulsion performance, generator function, and guarantee long-term vessel integrity.',
                    'icon' => 'gear',
                ],
                [
                    'title' => 'Seamless Operations',
                    'description' => 'Executing efficiently organized maritime solutions where diagnostic and mechanical procedures adhere strictly to international SOLAS requirements and class society safety standards.',
                    'icon' => 'shield',
                ],
                [
                    'title' => 'Dedicated Crews',
                    'description' => 'Our riding squads and highly trained marine engineers deploy globally in under 24 hours, minimizing off-hire time through agile, dockside, or mid-voyage interventions.',
                    'icon' => 'globe',
                ],
                [
                    'title' => 'Systems & Automation',
                    'description' => 'From power generation synchronization to pneumatic control repairs, ballast water treatment systems, and alarm monitoring, our crews possess deep multi-system expertise.',
                    'icon' => 'cpu',
                ],
            ],
            'standards' => [
                [
                    'title' => 'Zero Harm Culture',
                    'description' => 'Protecting our personnel, your crew, and the vessel is our primary directive. Rigorous risk assessments precede every intervention.',
                    'icon' => 'safety',
                    'color' => 'caution',
                ],
                [
                    'title' => 'Eco-Compliance',
                    'description' => 'We actively assist fleets in meeting evolving EEXI, CII, and emissions regulations through engine optimizations and retrofit installations.',
                    'icon' => 'globe',
                    'color' => 'blue',
                ],
                [
                    'title' => 'Total Transparency',
                    'description' => 'Detailed reporting, honest diagnostics, and strict adherence to initial quotes. Integrity drives our partnerships.',
                    'icon' => 'flask',
                    'color' => 'ocean',
                ],
            ],
        ];
    }
}
