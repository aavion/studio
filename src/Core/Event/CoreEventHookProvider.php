<?php

declare(strict_types=1);

namespace App\Core\Event;

use App\Content\Event\ContentRenderContextEvent;
use App\Core\Message\MessageKey;
use App\Core\Package\Event\PackageAssetRegistryBuildEvent;
use App\Core\Package\Event\PackageAssetSyncCompletedEvent;
use App\Core\Package\Event\PackageAssetSyncStartedEvent;
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
            MessageKey::EVENT_HOOK_VIEW_CONTEXT_SUMMARY,
            mutable: true,
        );

        yield new EventHookDescriptor(
            ContentRenderContextEvent::class,
            'content',
            EventHookMode::Extend,
            MessageKey::EVENT_HOOK_CONTENT_RENDER_CONTEXT_SUMMARY,
            mutable: true,
        );

        yield new EventHookDescriptor(
            PackageAssetSyncStartedEvent::class,
            'package',
            EventHookMode::Observe,
            MessageKey::EVENT_HOOK_PACKAGE_ASSET_SYNC_STARTED_SUMMARY,
        );

        yield new EventHookDescriptor(
            PackageAssetRegistryBuildEvent::class,
            'package',
            EventHookMode::Extend,
            MessageKey::EVENT_HOOK_PACKAGE_ASSET_REGISTRY_BUILD_SUMMARY,
            mutable: true,
        );

        yield new EventHookDescriptor(
            PackageAssetSyncCompletedEvent::class,
            'package',
            EventHookMode::Observe,
            MessageKey::EVENT_HOOK_PACKAGE_ASSET_SYNC_COMPLETED_SUMMARY,
        );
    }
}
