<?php

namespace App\Service;

use Symfony\Component\Routing\RouterInterface;

final class PageConfiguration
{
    public function __construct(
        private readonly RouterInterface $router,
        private readonly PageImages $pageImages,
    ) {
    }

    public function build(string $section = 'home'): array
    {
        $pages = [
            'dashboard' => [
                'label' => 'Dashboard',
                'title' => 'Operations Dashboard',
                'description' => 'Your Apex Marine workspace for operations, services, and support.',
            ],
            'home' => [
                'label' => 'Home',
                'title' => 'Home',
                'description' => 'Marine engineering, vessel maintenance, and worldwide operations support from Apex Marine.',
            ],
            'about' => [
                'label' => 'About',
                'title' => 'About',
                'description' => 'Meet Apex Marine and explore our engineering team, global service network, and marine standards.',
            ],
            'service' => [
                'label' => 'Service',
                'title' => 'Service',
                'description' => 'Explore Apex Marine\'s marine engineering, preventative maintenance, and global vessel operations services.',
            ],
            'contact' => [
                'label' => 'Contact',
                'title' => 'Contact',
                'description' => 'Contact Apex Marine\'s global operations team to discuss vessel repairs, maintenance, and dispatch requests.',
            ],
        ];

        $items = [];

        foreach ($pages as $key => $page) {
            $items[] = [
                'label' => $page['label'],
                'href' => $this->router->generate($key === 'dashboard' ? 'app_dashboard' : 'app_' . $key),
                'current' => $key === $section,
            ];
        }

        return [
            'dashboard' => [
                'title' => $pages[$section]['title'],
                'description' => $pages[$section]['description'],
            ],
            'sidebar' => [
                'brand' => 'Apex Marine',
                'logo_path' => $this->pageImages->resolve('Logo.png'),
                'items' => $items,
            ],
        ];
    }
}
