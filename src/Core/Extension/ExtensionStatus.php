<?php

declare(strict_types=1);

namespace App\Core\Extension;

enum ExtensionStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Removed = 'removed';
    case Faulty = 'faulty';
}
