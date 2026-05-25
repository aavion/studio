<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DemoControllerTest extends WebTestCase
{
    public function testItRendersFrontendDemoShell(): void
    {
        $client = self::createClient();
        $client->request('GET', '/demo/frontend');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.studio-frontend-shell');
        self::assertSelectorTextContains('h1', 'Frontend shell demo');
    }

    public function testItRendersBackendDemoShell(): void
    {
        $client = self::createClient();
        $client->request('GET', '/demo/backend');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.studio-admin-shell');
        self::assertSelectorTextContains('h1', 'Backend shell demo');
    }
}
