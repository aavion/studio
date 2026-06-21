<?php

declare(strict_types=1);

use App\Core\Extension\ExtensionCacheInterface;
use App\Core\Extension\ExtensionCacheRuntime;
use App\Core\Extension\ExtensionVendorFacade;

if (!function_exists('require_vendor')) {
    function require_vendor(string $package): bool
    {
        return ExtensionVendorFacade::requireVendor($package);
    }
}

if (!function_exists('extension_cache_set')) {
    function extension_cache_set(string $key, mixed $value, int $ttlSeconds = ExtensionCacheInterface::DEFAULT_TTL_SECONDS): bool
    {
        return ExtensionCacheRuntime::set($key, $value, $ttlSeconds);
    }
}

if (!function_exists('extension_cache_get')) {
    function extension_cache_get(string $key, mixed $default = null): mixed
    {
        return ExtensionCacheRuntime::get($key, $default);
    }
}

if (!function_exists('extension_cache_delete')) {
    function extension_cache_delete(string $key): bool
    {
        return ExtensionCacheRuntime::delete($key);
    }
}
