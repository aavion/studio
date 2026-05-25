<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Content\Event\ContentRenderContextEvent;
use App\Content\Event\ContentRenderedEvent;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class PublicContentRenderingTest extends WebTestCase
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

    public function testItDispatchesContentRenderContextHook(): void
    {
        $client = self::createClient();
        $eventDispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $calls = [];
        $eventDispatcher->addListener(ContentRenderContextEvent::class, static function (ContentRenderContextEvent $event) use (&$calls): void {
            $calls[] = [
                'title' => $event->contentView()->title(),
                'path' => $event->request()->getPathInfo(),
            ];
            $event->set('package_marker', 'demo');
        });

        $client->request('GET', '/news/first-update');

        self::assertResponseIsSuccessful();
        self::assertSame([[
            'title' => 'First seeded article',
            'path' => '/news/first-update',
        ]], $calls);
    }

    public function testItDispatchesContentRenderedHook(): void
    {
        $client = self::createClient();
        $eventDispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $eventDispatcher->addListener(ContentRenderedEvent::class, static function (ContentRenderedEvent $event): void {
            $event->appendContent('<!-- content-rendered-hook -->');
        });

        $client->request('GET', '/news/first-update');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('<!-- content-rendered-hook -->', (string) $client->getResponse()->getContent());
    }

    public function testItRendersSeededNavigation(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.studio-frontend-navigation', 'Home');
        self::assertSelectorTextContains('.studio-frontend-navigation', 'About');
        self::assertSelectorTextContains('.studio-frontend-navigation', 'News');
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
}
