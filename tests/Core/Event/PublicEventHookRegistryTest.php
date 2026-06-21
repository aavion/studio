<?php

declare(strict_types=1);

namespace App\Tests\Core\Event;

use App\Content\Event\ContentRenderContextEvent;
use App\Content\Event\ContentRenderedEvent;
use App\Content\ContentEventHookProvider;
use App\Core\Event\EventHookDescriptor;
use App\Core\Event\EventHookDescriptorProviderInterface;
use App\Core\Event\EventHookMode;
use App\Core\Event\EventMessageKey;
use App\Core\Event\PublicEventHookRegistry;
use App\Core\Extension\Event\ExtensionAssetRegistryBuildEvent;
use App\Core\Extension\ExtensionEventHookProvider;
use App\Navigation\Event\NavigationBuilderEvent;
use App\Navigation\NavigationEventHookProvider;
use App\View\Event\OutputGeneratedEvent;
use App\View\Event\ResponseHeadersEvent;
use App\View\Injection\ViewInjectionEventHookProvider;
use App\View\Injection\Event\DynamicViewInjectionRegistryEvent;
use App\View\Injection\Event\StaticViewInjectionRegistryEvent;
use App\View\ViewEventHookProvider;
use App\View\ViewContextEvent;
use PHPUnit\Framework\TestCase;

final class PublicEventHookRegistryTest extends TestCase
{
    public function testItListsDocumentedPublicHooksByEventClass(): void
    {
        $hooks = (new PublicEventHookRegistry())->byEventClass();

        self::assertArrayHasKey(ViewContextEvent::class, $hooks);
        self::assertArrayHasKey(ContentRenderContextEvent::class, $hooks);
        self::assertArrayHasKey(ContentRenderedEvent::class, $hooks);
        self::assertArrayHasKey(NavigationBuilderEvent::class, $hooks);
        self::assertArrayHasKey(StaticViewInjectionRegistryEvent::class, $hooks);
        self::assertArrayHasKey(DynamicViewInjectionRegistryEvent::class, $hooks);
        self::assertArrayHasKey(ResponseHeadersEvent::class, $hooks);
        self::assertArrayHasKey(OutputGeneratedEvent::class, $hooks);
        self::assertArrayHasKey(ExtensionAssetRegistryBuildEvent::class, $hooks);
        self::assertSame(EventHookMode::Extend, $hooks[ViewContextEvent::class]->mode());
        self::assertTrue($hooks[ViewContextEvent::class]->mutable());
        self::assertSame(EventMessageKey::EVENT_HOOK_VIEW_CONTEXT_SUMMARY, $hooks[ViewContextEvent::class]->summaryKey());
        self::assertSame('content', $hooks[ContentRenderContextEvent::class]->domain());
        self::assertTrue($hooks[ContentRenderContextEvent::class]->mutable());
        self::assertSame('content', $hooks[ContentRenderedEvent::class]->domain());
        self::assertTrue($hooks[ContentRenderedEvent::class]->mutable());
        self::assertSame('navigation', $hooks[NavigationBuilderEvent::class]->domain());
        self::assertTrue($hooks[NavigationBuilderEvent::class]->mutable());
        self::assertSame('view', $hooks[StaticViewInjectionRegistryEvent::class]->domain());
        self::assertTrue($hooks[StaticViewInjectionRegistryEvent::class]->mutable());
        self::assertSame('view', $hooks[DynamicViewInjectionRegistryEvent::class]->domain());
        self::assertTrue($hooks[DynamicViewInjectionRegistryEvent::class]->mutable());
        self::assertSame('http', $hooks[ResponseHeadersEvent::class]->domain());
        self::assertTrue($hooks[ResponseHeadersEvent::class]->mutable());
        self::assertSame('view', $hooks[OutputGeneratedEvent::class]->domain());
        self::assertTrue($hooks[OutputGeneratedEvent::class]->mutable());
        self::assertSame('extension', $hooks[ExtensionAssetRegistryBuildEvent::class]->domain());
        self::assertTrue($hooks[ExtensionAssetRegistryBuildEvent::class]->mutable());
    }

    public function testItAggregatesHookDescriptorProviders(): void
    {
        $hooks = (new PublicEventHookRegistry([
            new ContentEventHookProvider(),
            new NavigationEventHookProvider(),
            new ExtensionEventHookProvider(),
            new ViewEventHookProvider(),
            new ViewInjectionEventHookProvider(),
        ]))->hooks();

        self::assertCount(11, $hooks);
        self::assertSame(ContentRenderContextEvent::class, $hooks[0]->eventClass());
    }

    public function testFirstHookDescriptorWinsWhenProvidersDeclareTheSameEvent(): void
    {
        $hooks = (new PublicEventHookRegistry([
            new class implements EventHookDescriptorProviderInterface {
                public function hooks(): iterable
                {
                    yield new EventHookDescriptor(
                        ViewContextEvent::class,
                        'system',
                        EventHookMode::Extend,
                        EventMessageKey::EVENT_HOOK_VIEW_CONTEXT_SUMMARY,
                        true,
                    );
                }
            },
            new class implements EventHookDescriptorProviderInterface {
                public function hooks(): iterable
                {
                    yield new EventHookDescriptor(
                        ViewContextEvent::class,
                        'extension',
                        EventHookMode::Observe,
                        EventMessageKey::EVENT_HOOK_VIEW_CONTEXT_SUMMARY,
                        false,
                    );
                }
            },
        ]))->byEventClass();

        self::assertSame('system', $hooks[ViewContextEvent::class]->domain());
        self::assertSame(EventHookMode::Extend, $hooks[ViewContextEvent::class]->mode());
        self::assertTrue($hooks[ViewContextEvent::class]->mutable());
    }
}
