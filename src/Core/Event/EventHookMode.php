<?php

declare(strict_types=1);

namespace App\Core\Event;

enum EventHookMode: string
{
    case Observe = 'observe';
    case Extend = 'extend';
    case Replace = 'replace';
}
