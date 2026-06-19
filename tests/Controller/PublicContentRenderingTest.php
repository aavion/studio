<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Content\Event\ContentRenderContextEvent;
use App\Content\Event\ContentRenderedEvent;
use App\View\Injection\DynamicViewInjection;
use App\View\Injection\DynamicViewInjectionFilter;
use App\View\Injection\DynamicViewInjectionSlot;
use App\View\Injection\Event\DynamicViewInjectionRegistryEvent;
use App\View\Injection\Event\StaticViewInjectionRegistryEvent;
use App\View\Injection\StaticViewInjection;
use App\View\Injection\ViewSurface;
use Doctrine\DBAL\Connection;
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
            $event->set('extension_marker', 'demo');
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

    public function testItRendersDynamicContentSlotInjections(): void
    {
        $client = self::createClient();
        $eventDispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $eventDispatcher->addListener(DynamicViewInjectionRegistryEvent::class, static function (DynamicViewInjectionRegistryEvent $event): void {
            $event->addInjection(new DynamicViewInjection(
                'test-after-content',
                ViewSurface::Public,
                DynamicViewInjectionSlot::AfterContent,
                '@frontend/content/injections/slot.html.twig',
                DynamicViewInjectionFilter::realContent(['article']),
                label: 'ui.content.fields',
            ));
        });

        $client->request('GET', '/news/first-update');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-injection="test-after-content"]');
        self::assertSelectorTextContains('[data-injection="test-after-content"]', 'Content fields');
    }

    public function testItReportsFailedDynamicContentSlotInjections(): void
    {
        $logPattern = dirname(__DIR__, 2).'/var/log/test/message-*.log';

        foreach (glob($logPattern) ?: [] as $logFile) {
            unlink($logFile);
        }

        $client = self::createClient();
        $eventDispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $eventDispatcher->addListener(DynamicViewInjectionRegistryEvent::class, static function (DynamicViewInjectionRegistryEvent $event): void {
            $event->addInjection(new DynamicViewInjection(
                'broken-dynamic-injection',
                ViewSurface::Public,
                DynamicViewInjectionSlot::AfterContent,
                '@frontend/content/injections/missing.html.twig',
                DynamicViewInjectionFilter::realContent(['article']),
                label: 'ui.content.fields',
            ));
        });

        $client->request('GET', '/news/first-update');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-injection="broken-dynamic-injection"]');

        $messageLog = '';
        foreach (glob($logPattern) ?: [] as $logFile) {
            $messageLog .= (string) file_get_contents($logFile);
        }

        self::assertStringContainsString('message.view.dynamic_injection.render_failed', $messageLog);
        self::assertStringContainsString('broken-dynamic-injection', $messageLog);
    }

    public function testSchemaCustomTwigReplacesOnlyInnerFieldset(): void
    {
        $client = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        $this->setArticleCustomTwig(
            '<section class="schema-custom-fieldset" data-schema="{{ schema.identifier }}"><strong>{{ fields.teaser }}</strong></section>',
        );
        $eventDispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $eventDispatcher->addListener(DynamicViewInjectionRegistryEvent::class, static function (DynamicViewInjectionRegistryEvent $event): void {
            $event->addInjection(new DynamicViewInjection(
                'test-before-custom-fieldset',
                ViewSurface::Public,
                DynamicViewInjectionSlot::BeforeContent,
                '@frontend/content/injections/slot.html.twig',
                DynamicViewInjectionFilter::realContent(['article']),
                label: 'ui.empty_state.title',
            ));
            $event->addInjection(new DynamicViewInjection(
                'test-after-custom-fieldset',
                ViewSurface::Public,
                DynamicViewInjectionSlot::AfterContent,
                '@frontend/content/injections/slot.html.twig',
                DynamicViewInjectionFilter::realContent(['article']),
                label: 'ui.content.fields',
            ));
        });

        try {
            $client->request('GET', '/news/first-update');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'First seeded article');
            self::assertSelectorExists('[data-injection="test-before-custom-fieldset"]');
            self::assertSelectorExists('.schema-custom-fieldset[data-schema="article"]');
            self::assertSelectorTextContains('.schema-custom-fieldset', 'The test database includes a complete article.');
            self::assertSelectorExists('[data-injection="test-after-custom-fieldset"]');
            self::assertSelectorNotExists('.system-frontend-content-fields');

            $html = (string) $client->getResponse()->getContent();
            self::assertLessThan(
                strpos($html, 'schema-custom-fieldset'),
                strpos($html, 'test-before-custom-fieldset'),
            );
            self::assertLessThan(
                strpos($html, 'test-after-custom-fieldset'),
                strpos($html, 'schema-custom-fieldset'),
            );
        } finally {
            $connection->update('content_schema_version', ['custom_twig' => null], ['uid' => '10000000-0000-7000-8000-000000000102']);
        }
    }

    public function testInvalidSchemaCustomTwigFallsBackToGenericFieldset(): void
    {
        $client = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        $this->setArticleCustomTwig('{% if broken %}');

        try {
            $client->request('GET', '/news/first-update');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'First seeded article');
            self::assertSelectorExists('.system-frontend-content-fields');
            self::assertSelectorTextContains('.system-frontend-content-fields', 'Content fields');
        } finally {
            $connection->update('content_schema_version', ['custom_twig' => null], ['uid' => '10000000-0000-7000-8000-000000000102']);
        }
    }

    public function testItRendersDynamicRouteInjectionsOnlyForMissingContentVariants(): void
    {
        $client = self::createClient();
        $eventDispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $eventDispatcher->addListener(DynamicViewInjectionRegistryEvent::class, static function (DynamicViewInjectionRegistryEvent $event): void {
            $event->addInjection(new DynamicViewInjection(
                'test-comments-route',
                ViewSurface::Public,
                DynamicViewInjectionSlot::Route,
                '@frontend/content/injections/route.html.twig',
                DynamicViewInjectionFilter::realContent(['article']),
                variantSlug: 'comments',
                label: 'ui.content.fields',
            ));
        });

        $client->request('GET', '/news/first-update/~comments');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-injection="test-comments-route"]');
        self::assertSelectorTextContains('h1', 'Content fields');
    }

    public function testStaticPublicInjectionsRenderAfterContentRoutes(): void
    {
        $client = self::createClient();
        $eventDispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $eventDispatcher->addListener(StaticViewInjectionRegistryEvent::class, static function (StaticViewInjectionRegistryEvent $event): void {
            $event->addInjection(new StaticViewInjection(
                'test-public-docs',
                ViewSurface::Public,
                'extension-docs',
                'ui.content.fields',
                '@frontend/content/injections/static.html.twig',
            ));
            $event->addInjection(new StaticViewInjection(
                'test-public-content-conflict',
                ViewSurface::Public,
                'news/first-update',
                'ui.empty_state.title',
                '@frontend/content/injections/static.html.twig',
            ));
        });

        $client->request('GET', '/extension-docs');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-injection="test-public-docs"]');
        self::assertSelectorTextContains('h1', 'Content fields');

        $client->request('GET', '/news/first-update');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'First seeded article');
        self::assertSelectorNotExists('[data-injection="test-public-content-conflict"]');
    }

    public function testItRendersSeededNavigation(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.system-frontend-navigation', 'Home');
        self::assertSelectorTextContains('.system-frontend-navigation', 'About');
        self::assertSelectorTextContains('.system-frontend-navigation', 'News');
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

    private function setArticleCustomTwig(?string $customTwig): void
    {
        self::getContainer()->get(Connection::class)->update(
            'content_schema_version',
            ['custom_twig' => $customTwig],
            ['uid' => '10000000-0000-7000-8000-000000000102'],
        );
    }
}
