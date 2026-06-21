<?php

declare(strict_types=1);

use App\Core\Extension\ExtensionVendorFacade;

if (!function_exists('require_vendor')) {
    function require_vendor(string $package): bool
    {
        return ExtensionVendorFacade::requireVendor($package);
    }
}
