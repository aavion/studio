<?php

declare(strict_types=1);

namespace App\Tests\Core\Event;

use App\Content\Event\ContentRenderContextEvent;
use App\Core\Event\EventHookMode;
use App\Core\Event\CoreEventHookProvider;
use App\Core\Event\PublicEventHookRegistry;
use App\Core\Message\MessageKey;
use App\Core\Package\Event\PackageAssetRegistryBuildEvent;
use App\View\Event\OutputGeneratedEvent;
use App\View\Event\ResponseHeadersEvent;
use App\View\ViewContextEvent;
use PHPUnit\Framework\TestCase;

final class PublicEventHookRegistryTest extends TestCase
{
    public function testItListsDocumentedPublicHooksByEventClass(): void
    {
        $hooks = (new PublicEventHookRegistry())->byEventClass();

        self::assertArrayHasKey(ViewContextEvent::class, $hooks);
        self::assertArrayHasKey(ContentRenderContextEvent::class, $hooks);
        self::assertArrayHasKey(ResponseHeadersEvent::class, $hooks);
        self::assertArrayHasKey(OutputGeneratedEvent::class, $hooks);
        self::assertArrayHasKey(PackageAssetRegistryBuildEvent::class, $hooks);
        self::assertSame(EventHookMode::Extend, $hooks[ViewContextEvent::class]->mode());
        self::assertTrue($hooks[ViewContextEvent::class]->mutable());
        self::assertSame(MessageKey::EVENT_HOOK_VIEW_CONTEXT_SUMMARY, $hooks[ViewContextEvent::class]->summaryKey());
        self::assertSame('content', $hooks[ContentRenderContextEvent::class]->domain());
        self::assertTrue($hooks[ContentRenderContextEvent::class]->mutable());
        self::assertSame('http', $hooks[ResponseHeadersEvent::class]->domain());
        self::assertTrue($hooks[ResponseHeadersEvent::class]->mutable());
        self::assertSame('view', $hooks[OutputGeneratedEvent::class]->domain());
        self::assertTrue($hooks[OutputGeneratedEvent::class]->mutable());
        self::assertSame('package', $hooks[PackageAssetRegistryBuildEvent::class]->domain());
        self::assertTrue($hooks[PackageAssetRegistryBuildEvent::class]->mutable());
    }

    public function testItAggregatesHookDescriptorProviders(): void
    {
        $hooks = (new PublicEventHookRegistry([new CoreEventHookProvider()]))->hooks();

        self::assertCount(7, $hooks);
        self::assertSame(ViewContextEvent::class, $hooks[0]->eventClass());
    }
}
