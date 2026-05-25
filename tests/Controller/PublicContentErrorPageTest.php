<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicContentErrorPageTest extends WebTestCase
{
    use PublicContentTestDatabaseTrait;

    public function testItReturnsNotFoundForMissingContent(): void
    {
        $client = self::createClient();
        $client->request('GET', '/missing');

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('h1', 'Page not found');
    }

    public function testItRendersSystemErrorContentBeforeTemplateFallback(): void
    {
        $client = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        $this->seedSystemErrorPage($connection, 404, 'Custom missing page');

        try {
            $client->request('GET', '/missing');

            self::assertResponseStatusCodeSame(404);
            self::assertSelectorTextContains('h1', 'Custom missing page');
        } finally {
            $this->removeSystemErrorPage($connection, 404);
        }
    }
}
