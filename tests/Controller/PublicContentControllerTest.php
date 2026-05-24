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

    public function testItRedirectsRootToDefaultLanguageWhenLocalizedRoutesAreEnabled(): void
    {
        $client = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        $this->configureLocalizedRoutes($connection, true, 'de');

        try {
            $client->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => '']);

            self::assertResponseRedirects('/de', 302);
        } finally {
            $this->configureLocalizedRoutes($connection, false, 'en');
        }
    }

    public function testItRedirectsRootToBrowserLanguageWhenLocalizedRoutesAreEnabled(): void
    {
        $client = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        $this->configureLocalizedRoutes($connection, true, 'en');

        try {
            $client->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9,en;q=0.8']);

            self::assertResponseRedirects('/de', 302);
        } finally {
            $this->configureLocalizedRoutes($connection, false, 'en');
        }
    }

    public function testItFallsBackToDefaultLanguageWhenNoBrowserLanguageMatches(): void
    {
        $client = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        $this->configureLocalizedRoutes($connection, true, 'en');

        try {
            $client->request('GET', '/about', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr-FR,fr;q=0.9']);

            self::assertResponseRedirects('/en/about', 302);
        } finally {
            $this->configureLocalizedRoutes($connection, false, 'en');
        }
    }

    public function testItRedirectsUnprefixedContentToDefaultLanguageWhenLocalizedRoutesAreEnabled(): void
    {
        $client = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        $this->configureLocalizedRoutes($connection, true, 'de');

        try {
            $client->request('GET', '/about', ['variant' => 'compact'], server: ['HTTP_ACCEPT_LANGUAGE' => '']);

            self::assertResponseRedirects('/de/about?variant=compact', 302);
        } finally {
            $this->configureLocalizedRoutes($connection, false, 'en');
        }
    }

    public function testItRendersLocalizedRoutePrefixesWhenEnabled(): void
    {
        $client = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        $this->configureLocalizedRoutes($connection, true, 'de');

        try {
            $client->request('GET', '/de/about');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Ueber Studio');
        } finally {
            $this->configureLocalizedRoutes($connection, false, 'en');
        }
    }

    public function testItForbidsLocalizedReservedPrefixesWhenEnabled(): void
    {
        $client = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        $this->configureLocalizedRoutes($connection, true, 'de');

        try {
            $client->request('GET', '/de/system/footer');

            self::assertResponseStatusCodeSame(403);
        } finally {
            $this->configureLocalizedRoutes($connection, false, 'en');
        }
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

    public function testItFallsBackToDefaultForMissingVariant(): void
    {
        $client = self::createClient();
        $client->request('GET', '/', ['variant' => 'compact']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Welcome to Studio');
    }

    public function testItFallsBackToDefaultForMissingRouteVariantSuffix(): void
    {
        $client = self::createClient();
        $client->request('GET', '/~compact');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Welcome to Studio');
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

    public function testItReturnsUnauthorizedForAclDeniedContent(): void
    {
        $client = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        $connection->update('content_item', ['view_min_level' => AccessLevel::EDITOR], ['slug' => 'home']);

        try {
            $client->request('GET', '/');

            self::assertResponseStatusCodeSame(401);
        } finally {
            $connection->update('content_item', ['view_min_level' => AccessLevel::PUBLIC], ['slug' => 'home']);
        }
    }

    public function testItReturnsForbiddenForReservedContentPrefixes(): void
    {
        $client = self::createClient();
        $client->request('GET', '/system/footer');

        self::assertResponseStatusCodeSame(403);
    }

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
                'parent_uid' => null,
                'custom_url' => '/about',
            ], ['uid' => '20000000-0000-0000-0000-000000000002']);
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
            'uid' => '40000000-0000-0000-0000-000000000501',
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
            $connection->delete('content_field_value', ['uid' => '40000000-0000-0000-0000-000000000501']);
            $connection->update('content_item', [
                'slug' => 'about',
                'parent_uid' => null,
                'custom_url' => '/about',
                'available_variants' => json_encode(['default'], JSON_THROW_ON_ERROR),
            ], ['uid' => '20000000-0000-0000-0000-000000000002']);
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

    private function configureLocalizedRoutes(Connection $connection, bool $enabled, string $defaultLanguage): void
    {
        $connection->update('config_entry', [
            'value' => json_encode($enabled, JSON_THROW_ON_ERROR),
            'value_type' => 'boolean',
        ], ['config_key' => 'localization.route_prefixes_enabled']);
        $connection->update('config_entry', [
            'value' => json_encode($defaultLanguage, JSON_THROW_ON_ERROR),
            'value_type' => 'string',
        ], ['config_key' => 'localization.default_language']);
    }
}
