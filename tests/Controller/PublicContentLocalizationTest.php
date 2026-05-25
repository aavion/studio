<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicContentLocalizationTest extends WebTestCase
{
    use PublicContentTestDatabaseTrait;

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
}
