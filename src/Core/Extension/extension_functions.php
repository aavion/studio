<?php

declare(strict_types=1);

use App\Core\Extension\ExtensionCacheInterface;
use App\Core\Extension\ExtensionRuntime;
use App\Core\Extension\ExtensionVendorFacade;
use Symfony\Component\HttpFoundation\File\UploadedFile;

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

if (!function_exists('extension_file_get')) {
    function extension_file_get(string $path): ?string
    {
        return ExtensionRuntime::fileGet($path);
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

if (!function_exists('extension_mail')) {
    /**
     * @param string|list<string> $recipients
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $options
     */
    function extension_mail(string $workflow, string|array $recipients, array $parameters = [], array $options = []): bool
    {
        return ExtensionRuntime::mail($workflow, $recipients, $parameters, $options);
    }
}

if (!function_exists('extension_storage_put')) {
    /**
     * @param array<string, mixed> $options
     */
    function extension_storage_put(string $path, string $contents, array $options = []): bool
    {
        return ExtensionRuntime::storagePut($path, $contents, $options);
    }
}

if (!function_exists('extension_storage_get')) {
    function extension_storage_get(string $path): ?string
    {
        return ExtensionRuntime::storageGet($path);
    }
}

if (!function_exists('extension_storage_delete')) {
    function extension_storage_delete(string $path): bool
    {
        return ExtensionRuntime::storageDelete($path);
    }
}

if (!function_exists('extension_storage_exists')) {
    function extension_storage_exists(string $path): bool
    {
        return ExtensionRuntime::storageExists($path);
    }
}

if (!function_exists('extension_storage_list')) {
    /**
     * @param array<string, mixed> $options
     *
     * @return list<array{path: string, size: int, modified_at: int, expires_at: int|null}>
     */
    function extension_storage_list(string $prefix = '', array $options = []): array
    {
        return ExtensionRuntime::storageList($prefix, $options);
    }
}

if (!function_exists('extension_upload_store')) {
    /**
     * @param array<string, mixed> $options
     *
     * @return array{path: string, original_name: string, size: int, mime_type: string|null, extension: string|null}|null
     */
    function extension_upload_store(UploadedFile $file, string $targetPath, array $options = []): ?array
    {
        return ExtensionRuntime::uploadStore($file, $targetPath, $options);
    }
}

if (!function_exists('extension_request')) {
    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    function extension_request(array $options = []): array
    {
        return ExtensionRuntime::request($options);
    }
}

if (!function_exists('extension_content_query')) {
    /**
     * @param array<string, mixed> $criteria
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    function extension_content_query(array $criteria = [], array $options = []): array
    {
        return ExtensionRuntime::contentQuery($criteria, $options);
    }
}

if (!function_exists('extension_content_get')) {
    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    function extension_content_get(string $identifier, array $options = []): ?array
    {
        return ExtensionRuntime::contentGet($identifier, $options);
    }
}

if (!function_exists('extension_can')) {
    /**
     * @param array<string, mixed> $subject
     * @param array<string, mixed> $options
     */
    function extension_can(string $action, array $subject = [], array $options = []): bool
    {
        return ExtensionRuntime::can($action, $subject, $options);
    }
}

if (!function_exists('extension_lookup')) {
    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    function extension_lookup(string $type, string $identifier, array $options = []): ?array
    {
        return ExtensionRuntime::lookup($type, $identifier, $options);
    }
}

if (!function_exists('extension_entity')) {
    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    function extension_entity(string $uid, ?string $type = null, array $options = []): ?array
    {
        return ExtensionRuntime::entity($uid, $type, $options);
    }
}

if (!function_exists('extension_db_fetch')) {
    /**
     * @param array<string, mixed> $criteria
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    function extension_db_fetch(string $table, array $criteria = [], array $options = []): array
    {
        return ExtensionRuntime::dbFetch($table, $criteria, $options);
    }
}

if (!function_exists('extension_db_insert')) {
    /**
     * @param array<string, mixed> $row
     */
    function extension_db_insert(string $table, array $row): bool
    {
        return ExtensionRuntime::dbInsert($table, $row);
    }
}

if (!function_exists('extension_db_update')) {
    /**
     * @param array<string, mixed> $criteria
     * @param array<string, mixed> $values
     */
    function extension_db_update(string $table, array $criteria, array $values): int
    {
        return ExtensionRuntime::dbUpdate($table, $criteria, $values);
    }
}

if (!function_exists('extension_db_delete')) {
    /**
     * @param array<string, mixed> $criteria
     */
    function extension_db_delete(string $table, array $criteria): int
    {
        return ExtensionRuntime::dbDelete($table, $criteria);
    }
}
