<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicContentRedirectTest extends WebTestCase
{
    public function testItRendersInternalRedirectRouteWithoutChangingTheUrl(): void
    {
        $client = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        $connection->update('content_item', [
            'slug' => 'footer',
            'parent_uid' => 'system',
            'custom_url' => null,
        ], ['slug' => 'about']);
        $connection->update('content_item', ['redirect_target' => '/system/footer'], ['slug' => 'first-update']);

        try {
            $client->request('GET', '/news/first-update');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'About Studio');
            self::assertSame('/news/first-update', $client->getRequest()->getPathInfo());
        } finally {
            $connection->update('content_item', [
                'slug' => 'about',
                'parent_uid' => '/',
                'custom_url' => '/about',
            ], ['uid' => '20000000-0000-7000-8000-000000000002']);
            $connection->update('content_item', ['redirect_target' => null], ['slug' => 'first-update']);
        }
    }

    public function testItRendersHomepageForInternalRootRedirects(): void
    {
        $client = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        $connection->update('content_item', ['redirect_target' => '/'], ['slug' => 'about']);

        try {
            $client->request('GET', '/about');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Welcome to Studio');
            self::assertSame('/about', $client->getRequest()->getPathInfo());
        } finally {
            $connection->update('content_item', ['redirect_target' => null], ['slug' => 'about']);
        }
    }

    public function testItRendersInternalRedirectRouteVariantWithoutChangingTheUrl(): void
    {
        $client = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        $revisionUid = (string) $connection->fetchOne("SELECT active_revision_uid FROM content_item WHERE slug = 'about'");
        $connection->update('content_item', [
            'slug' => 'footer',
            'parent_uid' => 'system',
            'custom_url' => null,
            'available_variants' => json_encode(['default', 'compact'], JSON_THROW_ON_ERROR),
        ], ['slug' => 'about']);
        $connection->insert('content_field_value', [
            'uid' => '40000000-0000-7000-8000-000000000501',
            'revision_uid' => $revisionUid,
            'language' => 'en',
            'variant' => 'compact',
            'field_identifier' => 'title',
            'field_content' => json_encode('Compact Footer', JSON_THROW_ON_ERROR),
        ]);
        $connection->update('content_item', ['redirect_target' => '/system/footer/~compact'], ['slug' => 'first-update']);

        try {
            $client->request('GET', '/news/first-update');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Compact Footer');
            self::assertSame('/news/first-update', $client->getRequest()->getPathInfo());
        } finally {
            $connection->delete('content_field_value', ['uid' => '40000000-0000-7000-8000-000000000501']);
            $connection->update('content_item', [
                'slug' => 'about',
                'parent_uid' => '/',
                'custom_url' => '/about',
                'available_variants' => json_encode(['default'], JSON_THROW_ON_ERROR),
            ], ['uid' => '20000000-0000-7000-8000-000000000002']);
            $connection->update('content_item', ['redirect_target' => null], ['slug' => 'first-update']);
        }
    }

    public function testItRedirectsExternalRedirectTargetsWithFoundStatus(): void
    {
        $client = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        $connection->update('content_item', ['redirect_target' => 'https://example.test/target'], ['slug' => 'about']);

        try {
            $client->request('GET', '/about');

            self::assertResponseRedirects('https://example.test/target', 302);
        } finally {
            $connection->update('content_item', ['redirect_target' => null], ['slug' => 'about']);
        }
    }
}
