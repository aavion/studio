<?php

declare(strict_types=1);

namespace App\Core\Event;

use App\Content\ContentEventHookProvider;
use App\Core\Extension\ExtensionEventHookProvider;
use App\Navigation\NavigationEventHookProvider;
use App\View\Injection\ViewInjectionEventHookProvider;
use App\View\ViewEventHookProvider;

final class SystemEventHookProviders
{
    /**
     * @return list<EventHookDescriptorProviderInterface>
     */
    public static function defaults(): array
    {
        return [
            new ContentEventHookProvider(),
            new NavigationEventHookProvider(),
            new ExtensionEventHookProvider(),
            new ViewEventHookProvider(),
            new ViewInjectionEventHookProvider(),
        ];
    }
}
