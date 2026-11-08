<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DashboardControllerTest extends WebTestCase
{
    public function testSidebarLinksOpenTheirPages(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(4, '.sidebar nav a');

        foreach ([
            'About' => '/about',
            'Service' => '/service',
            'Contact' => '/contact',
            'Home' => '/',
        ] as $label => $path) {
            $crawler = $client->getCrawler();
            $client->click($crawler->filter('.sidebar nav a[href="'.$path.'"]')->link());
            self::assertResponseIsSuccessful();
            self::assertSame($path, $client->getRequest()->getPathInfo());
            self::assertSelectorTextContains('main h1', match ($label) {
                'Home' => 'Heavy-Duty Marine',
                default => $label,
            });
            self::assertSelectorCount(1, '.sidebar nav a[aria-current="page"]');
            self::assertSelectorTextContains('.sidebar nav a[aria-current="page"]', $label);
        }
    }

    public function testHomePageRemainsThePublicMarineLandingPage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '.home-page');
        self::assertSelectorCount(1, '.home-page > header');
        self::assertSelectorCount(3, '.home-page > section');
        self::assertSelectorTextContains('.home-hero h1', 'Heavy-Duty Marine');
        self::assertSelectorTextContains('.home-hero h1', 'Engineering & Maintenance');
        self::assertSelectorTextContains('.home-hero', 'Request Immediate Service');
        self::assertSelectorTextContains('.home-page', 'Excellence in Maritime Engineering');
        self::assertSelectorTextContains('.home-page', 'Our Core Services');
        self::assertSelectorTextContains('.home-page', 'Why Choose Apex?');
        self::assertSelectorCount(3, '.home-service-card');
        self::assertSelectorTextContains('.home-page', 'Expert Personnel');
        self::assertSelectorCount(1, '.topbar');
        self::assertSelectorCount(1, '.sidebar');
        self::assertSelectorCount(0, '.sidebar nav a[href="/dashboard"]');
        self::assertSelectorCount(1, '.dashboard-footer');
        self::assertSelectorCount(0, 'a[href*=".php"]');
    }

    public function testGuestsCannotAccessDashboardAndAreSentToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/dashboard');
        self::assertResponseRedirects('/login');

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action="/login"]');
    }

    public function testLoginPageRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action="/login"]');
        self::assertSelectorExists('input[name="email"]');
        self::assertSelectorExists('input[name="password"]');
        self::assertSelectorExists('input[name="_csrf_token"]');
    }

    public function testHomePageLinksOpenCanonicalPages(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '.home-button[href="/contact"]');
        self::assertSelectorCount(3, '.home-service-card[href="/service"]');

        $client->click($crawler->filter('.home-button')->link());
        self::assertResponseIsSuccessful();
        self::assertSame('/contact', $client->getRequest()->getPathInfo());
        self::assertSelectorTextContains('main h1', 'Contact');

        for ($index = 0; $index < 3; ++$index) {
            $crawler = $client->request('GET', '/');
            $client->click($crawler->filter('.home-service-card')->eq($index)->link());
            self::assertResponseIsSuccessful();
            self::assertSame('/service', $client->getRequest()->getPathInfo());
            self::assertSelectorTextContains('main h1', 'Service');
        }
    }

    public function testHomeAliasRendersTheHomePage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/home');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '.home-page');
        self::assertSelectorTextContains('.home-hero h1', 'Heavy-Duty Marine');
        self::assertSelectorTextContains('.home-hero h1', 'Engineering & Maintenance');
        self::assertSelectorCount(1, '.sidebar nav a[aria-current="page"]');
        self::assertSelectorTextContains('.sidebar nav a[aria-current="page"]', 'Home');
        self::assertSelectorExists('.sidebar nav a[aria-current="page"][href="/"]');
    }

    public function testConfigurationEndpoint(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/dashboard/config');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        $configuration = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('Home', $configuration['dashboard']['title']);
        self::assertSame([
            ['label' => 'Dashboard', 'href' => '/dashboard', 'current' => false],
            ['label' => 'Home', 'href' => '/', 'current' => true],
            ['label' => 'About', 'href' => '/about', 'current' => false],
            ['label' => 'Service', 'href' => '/service', 'current' => false],
            ['label' => 'Contact', 'href' => '/contact', 'current' => false],
        ], $configuration['sidebar']['items']);
    }

    public function testPageRoutesRenderTheirOwnTemplates(): void
    {
        $client = static::createClient();

        foreach (['about' => 'About', 'service' => 'Service', 'contact' => 'Contact'] as $page => $title) {
            $client->request('GET', '/' . $page);
            self::assertResponseIsSuccessful();
            self::assertSelectorCount(1, '.' . $page . '-page');
            self::assertSelectorTextContains('main h1', $title);
            self::assertSelectorTextContains('.sidebar nav a[aria-current="page"]', $title);
            self::assertSelectorCount(1, '.topbar');
            self::assertSelectorCount(1, '.dashboard-footer');
        }
    }

    public function testServicesAliasRedirectsToService(): void
    {
        $client = static::createClient();
        $client->request('GET', '/services');
        self::assertResponseRedirects('/service');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '.service-page');
    }

    public function testDashboardAliasesKeepTheirIntendedDestinations(): void
    {
        $client = static::createClient();
        foreach ([
            '/dashboard',
            '/dashboard/home',
            '/dashboard/overview',
            '/dashboard/about',
            '/dashboard/crew',
            '/dashboard/service',
            '/dashboard/vessels',
            '/dashboard/voyages',
            '/dashboard/maintenance',
            '/dashboard/reports',
            '/dashboard/contact',
        ] as $dashboardPath) {
            $client->request('GET', $dashboardPath);
            self::assertResponseRedirects('/login');
            $client->followRedirect();
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('form[action="/login"]');
        }
    }

    public function testReadOnlyRoutesRejectPost(): void
    {
        $client = static::createClient();
        foreach (['/', '/home', '/about', '/service', '/services', '/api/dashboard/config'] as $path) {
            $client->request('POST', $path);
            self::assertResponseStatusCodeSame(405);
        }
    }

    public function testPublicPagesDoNotStartASession(): void
    {
        $client = static::createClient();

        foreach (['/', '/home', '/about', '/service'] as $path) {
            $client->request('GET', $path);
            self::assertResponseIsSuccessful();
            self::assertSame([], $client->getResponse()->headers->getCookies(), $path.' should not set a session cookie.');
        }
    }

    public function testConventionalFaviconRedirectsToTheSvgIcon(): void
    {
        $client = static::createClient();
        $client->request('GET', '/favicon.ico');

        self::assertResponseRedirects('http://localhost/favicon.svg', 301);
    }

    public function testUnknownSectionReturnsNotFound(): void
    {
        $client = static::createClient();
        $client->request('GET', '/dashboard/unknown');
        self::assertResponseStatusCodeSame(404);
    }
}
