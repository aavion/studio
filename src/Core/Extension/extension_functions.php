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

if (!function_exists('extension_live_url')) {
    /**
     * @param array<string, mixed> $params
     */
    function extension_live_url(string $endpoint, array $params = []): ?string
    {
        return ExtensionRuntime::liveUrl($endpoint, $params);
    }
}

if (!function_exists('extension_api_url')) {
    /**
     * @param array<string, mixed> $params
     */
    function extension_api_url(string $endpoint, array $params = []): ?string
    {
        return ExtensionRuntime::apiUrl($endpoint, $params);
    }
}

if (!function_exists('extension_http_request')) {
    /**
     * @param array<string, mixed> $options
     * @return array{ok: bool, status: int|null, headers: array<string, list<string>>, body: string, json: mixed, error: string|null}
     */
    function extension_http_request(string $method, string $url, mixed $payload = null, array $options = []): array
    {
        return ExtensionRuntime::httpRequest($method, $url, $payload, $options);
    }
}

if (!function_exists('extension_log')) {
    /**
     * @param array<string, mixed> $context
     */
    function extension_log(string $level, string $message, array $context = []): bool
    {
        return ExtensionRuntime::log($level, $message, $context);
    }
}

if (!function_exists('extension_alert')) {
    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $options
     */
    function extension_alert(string $level, string $message, array $parameters = [], array $options = []): bool
    {
        return ExtensionRuntime::alert($level, $message, $parameters, $options);
    }
}
