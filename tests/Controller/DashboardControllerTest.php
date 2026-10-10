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

        foreach (['About' => '/about', 'Service' => '/service', 'Contact' => '/contact', 'Home' => '/'] as $label => $path) {
            $client->clickLink($label);
            self::assertResponseIsSuccessful();
            self::assertSame($path, $client->getRequest()->getPathInfo());
            self::assertSelectorTextContains('main h1', $label === 'Home' ? 'Heavy-Duty Marine' : $label);
            self::assertSelectorCount(1, '.sidebar nav a[aria-current="page"]');
            self::assertSelectorTextContains('.sidebar nav a[aria-current="page"]', $label);
        }
    }

    public function testHomePageContainsAllFourSections(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '.home-page');
        self::assertSelectorCount(1, '.home-page > header');
        self::assertSelectorCount(3, '.home-page > section');
        self::assertSelectorCount(1, '.home-hero h1');
        self::assertSelectorTextContains('.home-hero h1', 'Heavy-Duty Marine');
        self::assertSelectorTextContains('.home-hero h1', 'Engineering & Maintenance');
        self::assertSelectorTextContains('.home-hero', 'Request Immediate Service');

        $sections = $crawler->filter('.home-page > section');
        foreach ([
            ['Excellence in Maritime Engineering', 'Heavy-Duty Ship Repairs', 'Trust & Reliability'],
            ['Our Core Services', 'Marine Engineering', 'Preventative Maintenance', 'Seamless Operation'],
            ['Why Choose Apex?', 'Expert Personnel', 'Proven Reliability'],
        ] as $index => $expectedContent) {
            foreach ($expectedContent as $text) {
                self::assertStringContainsString($text, $sections->eq($index)->text());
            }
        }

        self::assertSelectorCount(1, '.topbar');
        self::assertSelectorCount(1, '.sidebar');
        self::assertSelectorCount(1, '.dashboard-footer');
        self::assertSelectorCount(0, 'a[href*=".php"]');
    }

    public function testHomePageCallsToActionOpenCanonicalPages(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '.home-button[href="/contact"]');
        self::assertSelectorCount(3, 'a.home-service-card[href="/service"]');

        $client->click($crawler->filter('.home-button')->link());
        self::assertResponseIsSuccessful();
        self::assertSame('/contact', $client->getRequest()->getPathInfo());
        self::assertSelectorTextContains('main h1', 'Contact');

        for ($index = 0; $index < 3; ++$index) {
            $crawler = $client->request('GET', '/');
            $client->click($crawler->filter('a.home-service-card')->eq($index)->link());
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

    public function testLegacyDashboardRoutesRedirect(): void
    {
        $client = static::createClient();
        foreach ([
            '/dashboard' => '/', '/dashboard/home' => '/', '/dashboard/overview' => '/',
            '/dashboard/about' => '/about', '/dashboard/crew' => '/about',
            '/dashboard/service' => '/service', '/dashboard/vessels' => '/service',
            '/dashboard/voyages' => '/service', '/dashboard/maintenance' => '/service',
            '/dashboard/reports' => '/service', '/dashboard/contact' => '/contact',
        ] as $oldPath => $newPath) {
            $client->request('GET', $oldPath);
            self::assertResponseRedirects($newPath);
            $client->followRedirect();
            self::assertResponseIsSuccessful();
        }
    }

    public function testReadOnlyRoutesRejectPost(): void
    {
        $client = static::createClient();
        foreach (['/', '/home', '/about', '/service', '/services', '/dashboard', '/dashboard/voyages', '/api/dashboard/config'] as $path) {
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
