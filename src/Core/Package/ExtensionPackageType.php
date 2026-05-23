<?php

declare(strict_types=1);

namespace App\Core\Package;

enum ExtensionPackageType: string
{
    case Theme = 'theme';
    case Module = 'module';
}
