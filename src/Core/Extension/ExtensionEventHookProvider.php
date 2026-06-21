<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Event\EventHookDescriptor;
use App\Core\Event\EventHookDescriptorProviderInterface;
use App\Core\Event\EventHookMode;
use App\Core\Event\EventMessageKey;
use App\Core\Extension\Event\ExtensionAssetRegistryBuildEvent;
use App\Core\Extension\Event\ExtensionAssetSyncCompletedEvent;
use App\Core\Extension\Event\ExtensionAssetSyncStartedEvent;

final readonly class ExtensionEventHookProvider implements EventHookDescriptorProviderInterface
{
    /**
     * @return iterable<EventHookDescriptor>
     */
    public function hooks(): iterable
    {
        yield new EventHookDescriptor(
            ExtensionAssetSyncStartedEvent::class,
            'extension',
            EventHookMode::Observe,
            EventMessageKey::EVENT_HOOK_EXTENSION_ASSET_SYNC_STARTED_SUMMARY,
        );

        yield new EventHookDescriptor(
            ExtensionAssetRegistryBuildEvent::class,
            'extension',
            EventHookMode::Extend,
            EventMessageKey::EVENT_HOOK_EXTENSION_ASSET_REGISTRY_BUILD_SUMMARY,
            mutable: true,
        );

        yield new EventHookDescriptor(
            ExtensionAssetSyncCompletedEvent::class,
            'extension',
            EventHookMode::Observe,
            EventMessageKey::EVENT_HOOK_EXTENSION_ASSET_SYNC_COMPLETED_SUMMARY,
        );
    }
}
