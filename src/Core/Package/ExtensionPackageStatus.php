<?php

declare(strict_types=1);

namespace App\Core\Package;

enum ExtensionPackageStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Archived = 'archived';
}
