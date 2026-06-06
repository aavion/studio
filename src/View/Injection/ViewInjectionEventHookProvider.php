<?php

declare(strict_types=1);

namespace App\View\Injection;

use App\Core\Event\EventHookDescriptor;
use App\Core\Event\EventHookDescriptorProviderInterface;
use App\Core\Event\EventHookMode;
use App\Core\Event\EventMessageKey;
use App\View\Injection\Event\DynamicViewInjectionRegistryEvent;
use App\View\Injection\Event\StaticViewInjectionRegistryEvent;

final readonly class ViewInjectionEventHookProvider implements EventHookDescriptorProviderInterface
{
    /**
     * @return iterable<EventHookDescriptor>
     */
    public function hooks(): iterable
    {
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
    }
}
