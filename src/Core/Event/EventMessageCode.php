<?php

declare(strict_types=1);

namespace App\Core\Event;

final class EventMessageCode
{
    public const EVENT_HOOK_INVALID = 'event.hook_invalid';
    public const EVENT_HOOK_UNREGISTERED = 'event.hook_unregistered';
    public const EVENT_HOOK_LISTENER_FAILED = 'event.hook_listener_failed';
}
