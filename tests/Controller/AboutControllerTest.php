<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AboutControllerTest extends WebTestCase
{
    public function testAboutPagePreservesTheSuppliedSectionsAndSharedLayout(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/about');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '.about-page');
        self::assertSelectorCount(1, 'main h1');
        self::assertSelectorTextContains('.about-hero h1', 'About Apex Marine');
        self::assertSelectorTextContains('.about-hero', 'A Century of Maritime Excellence');

        foreach (['.about-stats', '#about-heritage', '#about-infrastructure', '#about-competencies', '#about-standard', '#about-cta'] as $section) {
            self::assertSelectorCount(1, $section);
        }

        self::assertSelectorCount(4, '.about-stat');
        foreach ([
            ['110+', 'Years Active'],
            ['24/7', 'Global Dispatch'],
            ['45+', 'Major Ports'],
            ['12k', 'Vessels Serviced'],
        ] as $index => [$value, $label]) {
            $statistic = $crawler->filter('.about-stat')->eq($index)->text();
            self::assertStringContainsString($value, preg_replace('/\s+/u', '', $statistic));
            self::assertStringContainsString($label, $statistic);
        }

        self::assertSelectorTextContains('#about-heritage', 'Forged in the');
        self::assertSelectorTextContains('#about-heritage', 'Deep Blue');
        self::assertSelectorTextContains('#about-heritage', 'Founded in 1910');
        self::assertSelectorTextContains('#about-heritage', '9001:2015');
        self::assertSelectorTextContains('#about-infrastructure', 'Infrastructure');
        self::assertSelectorCount(4, '#about-competencies .about-card');
        foreach (['Heavy-Duty Repairs', 'Seamless Operations', 'Dedicated Crews', 'Systems & Automation'] as $index => $title) {
            self::assertStringContainsString($title, $crawler->filter('#about-competencies .about-card')->eq($index)->text());
        }

        self::assertSame(
            ['Zero Harm Culture', 'Eco-Compliance', 'Total Transparency'],
            $crawler->filter('#about-standard .about-standard-title')->each(static fn ($heading): string => $heading->text())
        );
        self::assertSelectorTextContains('#about-cta', 'Ready to Secure Your Fleet?');
        self::assertSelectorCount(1, '.topbar');
        self::assertSelectorCount(1, '.sidebar');
        self::assertSelectorCount(1, '.dashboard-footer');
        self::assertSelectorCount(1, '.sidebar nav a[aria-current="page"]');
        self::assertSelectorTextContains('.sidebar nav a[aria-current="page"][href="/about"]', 'About');
        self::assertSelectorCount(0, 'a[href*=".php"], img[src^="../assets/"]');
        self::assertStringNotContainsString('<?php', $client->getResponse()->getContent());
    }

    public function testGlobalHubsHaveAccessibleNamesAndUniqueDescriptions(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/about');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(7, 'button.about-hub-marker');
        self::assertSelectorCount(7, '.about-hub-tooltip');

        $descriptions = [
            'Singapore' => 'Rapid Deployment Zone',
            'Shanghai' => 'Dry-Dock Facility',
            'Rotterdam' => 'Command Center',
            'Dubai' => 'Mechanical Overhaul',
            'Cape Town' => 'Emergency Dispatch',
            'Houston' => 'Structural Repair',
            'Panama City' => 'Mid-Voyage Interventions',
        ];
        $tooltipIds = [];

        foreach ($descriptions as $city => $description) {
            $marker = $crawler->filter('button.about-hub-marker')->reduce(
                static fn ($button): bool => str_contains($button->attr('aria-label') ?? '', $city)
            );
            self::assertCount(1, $marker, $city . ' should have a named, keyboard-accessible hub marker.');
            self::assertSame('button', $marker->attr('type'));
            $tooltipId = $marker->attr('aria-describedby');
            self::assertNotEmpty($tooltipId);
            self::assertNotContains($tooltipId, $tooltipIds);
            $tooltipIds[] = $tooltipId;

            $tooltip = $crawler->filter('.about-hub-tooltip')->reduce(
                static fn ($node): bool => $node->attr('id') === $tooltipId
            );
            self::assertCount(1, $tooltip, $city . ' should reference one existing tooltip.');
            self::assertStringContainsString($city, $tooltip->text());
            self::assertStringContainsString($description, $tooltip->text());
        }
    }

    public function testAboutCallsToActionNavigateToCanonicalPages(): void
    {
        $client = static::createClient();

        foreach (['Contact Operations' => '/contact', 'View Capabilities' => '/service'] as $label => $path) {
            $crawler = $client->request('GET', '/about');
            self::assertResponseIsSuccessful();
            $link = $crawler->filter('#about-cta')->selectLink($label)->link();
            self::assertSame($path, parse_url($link->getUri(), PHP_URL_PATH));

            $client->click($link);
            self::assertResponseIsSuccessful();
            self::assertSame($path, $client->getRequest()->getPathInfo());
            self::assertSelectorCount(1, '.' . ltrim($path, '/') . '-page');
        }
    }
}
