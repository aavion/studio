<?php

declare(strict_types=1);

namespace App\Core\Event;

use App\Content\Event\ContentRenderContextEvent;
use App\Content\Event\ContentRenderedEvent;
use App\Core\Event\EventMessageKey;
use App\Core\Package\Event\PackageAssetRegistryBuildEvent;
use App\Core\Package\Event\PackageAssetSyncCompletedEvent;
use App\Core\Package\Event\PackageAssetSyncStartedEvent;
use App\Navigation\Event\NavigationBuilderEvent;
use App\View\Event\OutputGeneratedEvent;
use App\View\Event\ResponseHeadersEvent;
use App\View\Injection\Event\DynamicViewInjectionRegistryEvent;
use App\View\Injection\Event\StaticViewInjectionRegistryEvent;
use App\View\ViewContextEvent;

final readonly class CoreEventHookProvider implements EventHookDescriptorProviderInterface
{
    /**
     * @return iterable<EventHookDescriptor>
     */
    public function hooks(): iterable
    {
        yield new EventHookDescriptor(
            ViewContextEvent::class,
            'view',
            EventHookMode::Extend,
            EventMessageKey::EVENT_HOOK_VIEW_CONTEXT_SUMMARY,
            mutable: true,
        );

        yield new EventHookDescriptor(
            ContentRenderContextEvent::class,
            'content',
            EventHookMode::Extend,
            EventMessageKey::EVENT_HOOK_CONTENT_RENDER_CONTEXT_SUMMARY,
            mutable: true,
        );

        yield new EventHookDescriptor(
            ContentRenderedEvent::class,
            'content',
            EventHookMode::Extend,
            EventMessageKey::EVENT_HOOK_CONTENT_RENDERED_SUMMARY,
            mutable: true,
        );

        yield new EventHookDescriptor(
            NavigationBuilderEvent::class,
            'navigation',
            EventHookMode::Extend,
            EventMessageKey::EVENT_HOOK_NAVIGATION_BUILDER_SUMMARY,
            mutable: true,
        );

        yield new EventHookDescriptor(
            StaticViewInjectionRegistryEvent::class,
            'view',
            EventHookMode::Extend,
            EventMessageKey::EVENT_HOOK_STATIC_VIEW_INJECTION_REGISTRY_SUMMARY,
            mutable: true,
        );

        yield new EventHookDescriptor(
            DynamicViewInjectionRegistryEvent::class,
            'view',
            EventHookMode::Extend,
            EventMessageKey::EVENT_HOOK_DYNAMIC_VIEW_INJECTION_REGISTRY_SUMMARY,
            mutable: true,
        );

        yield new EventHookDescriptor(
            ResponseHeadersEvent::class,
            'http',
            EventHookMode::Extend,
            EventMessageKey::EVENT_HOOK_RESPONSE_HEADERS_SUMMARY,
            mutable: true,
        );

        yield new EventHookDescriptor(
            OutputGeneratedEvent::class,
            'view',
            EventHookMode::Extend,
            EventMessageKey::EVENT_HOOK_OUTPUT_GENERATED_SUMMARY,
            mutable: true,
        );

        yield new EventHookDescriptor(
            PackageAssetSyncStartedEvent::class,
            'package',
            EventHookMode::Observe,
            EventMessageKey::EVENT_HOOK_PACKAGE_ASSET_SYNC_STARTED_SUMMARY,
        );

        yield new EventHookDescriptor(
            PackageAssetRegistryBuildEvent::class,
            'package',
            EventHookMode::Extend,
            EventMessageKey::EVENT_HOOK_PACKAGE_ASSET_REGISTRY_BUILD_SUMMARY,
            mutable: true,
        );

        yield new EventHookDescriptor(
            PackageAssetSyncCompletedEvent::class,
            'package',
            EventHookMode::Observe,
            EventMessageKey::EVENT_HOOK_PACKAGE_ASSET_SYNC_COMPLETED_SUMMARY,
        );
    }
}
