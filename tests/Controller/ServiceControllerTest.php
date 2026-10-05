<?php

namespace App\Tests\Controller;

use App\Controller\ServiceController;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ServiceControllerTest extends WebTestCase
{
    public function testServicePagePreservesTheSuppliedSectionsAndSharedLayout(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/service');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '.service-page');
        self::assertSelectorCount(1, 'main h1');
        self::assertSelectorTextContains('.service-hero h1', 'Our Core Services');
        self::assertSelectorTextContains('.service-hero', 'Comprehensive Solutions');
        self::assertSelectorTextContains('.service-hero', 'certified OEM-trained engineers');

        foreach (['disciplines', 'engineering', 'maintenance', 'operation', 'process', 'service-cta'] as $section) {
            self::assertSelectorCount(1, '.service-page section#' . $section);
        }

        self::assertSelectorCount(4, '#disciplines article.service-discipline-card');
        foreach ([
            ['Propulsion & Machinery', '2-stroke/4-stroke main engine overhauls'],
            ['Electrical & Automation', 'PLC programming'],
            ['Hydraulics & Deck Gear', 'Mooring winches'],
            ['Hull & Steel Fabrication', 'Class-approved welding (ABS/DNV)'],
        ] as $index => [$title, $description]) {
            $discipline = $crawler->filter('#disciplines article.service-discipline-card')->eq($index)->text();
            self::assertStringContainsString($title, $discipline);
            self::assertStringContainsString($description, $discipline);
        }

        self::assertSelectorTextContains('#service-cta', 'Minimize Downtime. Maximize Output.');
        self::assertSelectorCount(1, '.topbar');
        self::assertSelectorCount(1, '.sidebar');
        self::assertSelectorCount(1, '.dashboard-footer');
        self::assertSelectorCount(1, '.sidebar nav a[aria-current="page"]');
        self::assertSelectorTextContains('.sidebar nav a[aria-current="page"][href="/service"]', 'Service');
        self::assertSelectorCount(0, 'a[href*=".php"], img[src^="../assets/"]');
        self::assertStringNotContainsString('<?php', $client->getResponse()->getContent());

        $route = static::getContainer()->get('router')->match('/service');
        self::assertSame('app_service', $route['_route']);
        self::assertSame(ServiceController::class . '::index', $route['_controller']);
    }

    public function testTechnicalDetailsAndExecutionProtocolPreserveTheSuppliedContent(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/service');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#engineering', 'root-cause analysis to prevent recurrent failures');
        self::assertSame([
            'Crankshaft Deflection & Alignment',
            'Governor & Actuator Calibration',
            'Fuel Injection Timing & Overhaul',
            'Purifier & Separator Rebuilds',
        ], $crawler->filter('#engineering ul.service-engineering-list > li')->each(
            static fn ($item): string => trim($item->text())
        ));

        self::assertSelectorTextContains('#maintenance .service-note', 'Condition-Based Monitoring (CBM)');
        self::assertSelectorTextContains('#maintenance .service-note', 'thermography, vibration analysis, and lube oil particulate testing');
        self::assertSelectorCount(2, '#maintenance .service-detail-list > li');
        self::assertSelectorTextContains('#maintenance .service-detail-list', 'Dry-Dock Preparation:');
        self::assertSelectorTextContains('#maintenance .service-detail-list', 'Lifecycle Management:');
        self::assertSelectorTextContains('#maintenance .service-note', 'when needed—maximizing component lifespan.');

        self::assertSelectorTextContains('#operation', 'mid-voyage repairs without interrupting transit');
        self::assertSelectorTextContains('#operation', 'Global Service Network');
        self::assertSelectorCount(3, '#operation .service-detail-list > li');
        foreach ([
            ['APAC Hub:', 'Singapore & Shanghai', 'Rapid Deployment Zone'],
            ['EMEA Hub:', 'Rotterdam, Dubai & Cape Town'],
            ['Americas Hub:', 'Houston & Panama City'],
        ] as $index => $content) {
            $hub = $crawler->filter('#operation .service-detail-list > li')->eq($index)->text();
            foreach ($content as $text) {
                self::assertStringContainsString($text, $hub);
            }
        }

        self::assertSelectorTextContains('#process', 'The Execution Protocol');
        self::assertSelectorCount(4, '#process ol.service-process-grid > li.service-step');
        foreach ([
            ['Diagnosis', 'Remote assessment and root-cause analysis'],
            ['Mobilization', 'Dispatch of OEM-certified engineers'],
            ['Execution', 'dockside, at anchorage, or mid-voyage'],
            ['Class Approval', 'IACS classification societies'],
        ] as $index => [$title, $description]) {
            $step = $crawler->filter('#process ol.service-process-grid > li.service-step')->eq($index)->text();
            self::assertStringContainsString($title, $step);
            self::assertStringContainsString($description, $step);
        }
    }

    public function testServiceCallsToActionNavigateToTheContactPage(): void
    {
        $client = static::createClient();

        foreach (['Request Service Dispatch', 'Contact Engineering'] as $label) {
            $crawler = $client->request('GET', '/service');
            self::assertResponseIsSuccessful();
            $link = $crawler->filter('#service-cta')->selectLink($label)->link();
            self::assertSame('/contact', parse_url($link->getUri(), PHP_URL_PATH));

            $client->click($link);
            self::assertResponseIsSuccessful();
            self::assertSame('/contact', $client->getRequest()->getPathInfo());
            self::assertSelectorCount(1, '.contact-page');
        }
    }
}
