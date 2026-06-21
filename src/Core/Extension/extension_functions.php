<?php

declare(strict_types=1);

use App\Core\Extension\ExtensionCacheInterface;
use App\Core\Extension\ExtensionRuntime;
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
        return ExtensionRuntime::cacheSet($key, $value, $ttlSeconds);
    }
}

if (!function_exists('extension_cache_get')) {
    function extension_cache_get(string $key, mixed $default = null): mixed
    {
        return ExtensionRuntime::cacheGet($key, $default);
    }
}

if (!function_exists('extension_cache_delete')) {
    function extension_cache_delete(string $key): bool
    {
        return ExtensionRuntime::cacheDelete($key);
    }
}

if (!function_exists('extension_settings_get')) {
    function extension_settings_get(string $key, mixed $default = null): mixed
    {
        return ExtensionRuntime::settingsGet($key, $default);
    }
}

if (!function_exists('extension_asset')) {
    function extension_asset(string $path, bool $private = false): ?string
    {
        return ExtensionRuntime::asset($path, $private);
    }
}

if (!function_exists('extension_asset_url')) {
    function extension_asset_url(string $path): ?string
    {
        return ExtensionRuntime::assetUrl($path);
    }
}
