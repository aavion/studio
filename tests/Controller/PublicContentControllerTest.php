<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Core\Access\AccessLevel;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicContentControllerTest extends WebTestCase
{
    public function testItRendersSeededHomeContent(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Welcome to Studio');
        self::assertSelectorTextContains('article p', 'A flexible content seed for tests.');
    }

    public function testItRendersRequestedLanguageWhenAvailable(): void
    {
        $client = self::createClient();
        $client->request('GET', '/', ['language' => 'de']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Willkommen in Studio');
    }

    public function testItRendersCustomContentPath(): void
    {
        $client = self::createClient();
        $client->request('GET', '/news/first-update');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'First seeded article');
    }

    public function testItReturnsNotFoundForMissingContent(): void
    {
        $client = self::createClient();
        $client->request('GET', '/missing');

        self::assertResponseStatusCodeSame(404);
    }

    public function testItReturnsNotFoundForMissingVariant(): void
    {
        $client = self::createClient();
        $client->request('GET', '/', ['variant' => 'compact']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testItReturnsForbiddenForPrivateContent(): void
    {
        $client = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        $connection->update('content_item', ['visibility' => 'private'], ['slug' => 'home']);

        try {
            $client->request('GET', '/');

            self::assertResponseStatusCodeSame(403);
        } finally {
            $connection->update('content_item', ['visibility' => 'public'], ['slug' => 'home']);
        }
    }

    public function testItReturnsNotFoundForUnpublishedContent(): void
    {
        $client = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        $connection->update('content_item', ['status' => 'draft'], ['slug' => 'home']);

        try {
            $client->request('GET', '/');

            self::assertResponseStatusCodeSame(404);
        } finally {
            $connection->update('content_item', ['status' => 'published'], ['slug' => 'home']);
        }
    }

    public function testItReturnsForbiddenForAclDeniedContent(): void
    {
        $client = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        $connection->update('content_item', ['view_min_level' => AccessLevel::EDITOR], ['slug' => 'home']);

        try {
            $client->request('GET', '/');

            self::assertResponseStatusCodeSame(403);
        } finally {
            $connection->update('content_item', ['view_min_level' => AccessLevel::PUBLIC], ['slug' => 'home']);
        }
    }

    public function testItReturnsNotFoundForReservedContentPrefixes(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/missing');

        self::assertResponseStatusCodeSame(404);
    }
}
