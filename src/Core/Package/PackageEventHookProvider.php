<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Event\EventHookDescriptor;
use App\Core\Event\EventHookDescriptorProviderInterface;
use App\Core\Event\EventHookMode;
use App\Core\Event\EventMessageKey;
use App\Core\Package\Event\PackageAssetRegistryBuildEvent;
use App\Core\Package\Event\PackageAssetSyncCompletedEvent;
use App\Core\Package\Event\PackageAssetSyncStartedEvent;

final readonly class PackageEventHookProvider implements EventHookDescriptorProviderInterface
{
    /**
     * @return iterable<EventHookDescriptor>
     */
    public function hooks(): iterable
    {
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
