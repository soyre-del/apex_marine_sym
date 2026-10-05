<?php

namespace App\Service;

use Symfony\Component\Routing\RouterInterface;

final class PageConfiguration
{
    public function __construct(private readonly RouterInterface $router)
    {
    }

    public function build(string $section = 'home'): array
    {
        $pages = [
            'home' => ['label' => 'Home', 'title' => 'Home', 'description' => 'Welcome to Apex Marine'],
            'about' => ['label' => 'About', 'title' => 'About', 'description' => 'About Apex Marine'],
            'service' => ['label' => 'Service', 'title' => 'Service', 'description' => 'Apex Marine services'],
            'contact' => ['label' => 'Contact', 'title' => 'Contact', 'description' => 'Contact Apex Marine'],
        ];

        $items = [];

        foreach ($pages as $key => $page) {
            $items[] = [
                'label' => $page['label'],
                'href' => $this->router->generate('app_' . $key),
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
                'items' => $items,
            ],
        ];
    }
}
