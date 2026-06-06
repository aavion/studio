<?php

declare(strict_types=1);

namespace App\Navigation;

use App\Core\Event\EventHookDescriptor;
use App\Core\Event\EventHookDescriptorProviderInterface;
use App\Core\Event\EventHookMode;
use App\Core\Event\EventMessageKey;
use App\Navigation\Event\NavigationBuilderEvent;

final readonly class NavigationEventHookProvider implements EventHookDescriptorProviderInterface
{
    /**
     * @return iterable<EventHookDescriptor>
     */
    public function hooks(): iterable
    {
        yield new EventHookDescriptor(
            NavigationBuilderEvent::class,
            'navigation',
            EventHookMode::Extend,
            EventMessageKey::EVENT_HOOK_NAVIGATION_BUILDER_SUMMARY,
            mutable: true,
        );
    }
}
