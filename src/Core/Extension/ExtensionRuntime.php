<?php

declare(strict_types=1);

namespace App\Core\Extension;

final class ExtensionRuntime
{
    private static ?ExtensionRuntimeServices $services = null;

    private function __construct()
    {
    }

    public static function configure(ExtensionRuntimeServices $services): void
    {
        self::$services = $services;
    }

    public static function cacheSet(string $key, mixed $value, int $ttlSeconds = ExtensionCacheInterface::DEFAULT_TTL_SECONDS): bool
    {
        $slug = self::callerExtensionSlug();
        $cache = self::$services?->cache();

        return null !== $slug && null !== $cache && $cache->set($slug, $key, $value, $ttlSeconds);
    }

    public static function cacheGet(string $key, mixed $default = null): mixed
    {
        $slug = self::callerExtensionSlug();
        $cache = self::$services?->cache();

        return null !== $slug && null !== $cache ? $cache->get($slug, $key, $default) : $default;
    }

    public static function cacheDelete(string $key): bool
    {
        $slug = self::callerExtensionSlug();
        $cache = self::$services?->cache();

        return null !== $slug && null !== $cache && $cache->delete($slug, $key);
    }

    public static function settingsGet(string $key, mixed $default = null): mixed
    {
        $slug = self::callerExtensionSlug();
        $settings = self::$services?->settings();

        return null !== $slug && null !== $settings ? $settings->get($slug, $key, $default) : $default;
    }

    public static function fileGet(string $path): ?string
    {
        $slug = self::callerExtensionSlug();
        $files = self::$services?->files();

        return null !== $slug && null !== $files ? $files->read($slug, $path) : null;
    }

    public static function asset(string $path, bool $private = false): ?string
    {
        $slug = self::callerExtensionSlug();
        $assets = self::$services?->assets();

        return null !== $slug && null !== $assets ? $assets->read($slug, $path, $private) : null;
    }

    public static function assetUrl(string $path): ?string
    {
        $slug = self::callerExtensionSlug();
        $assetUrls = self::$services?->assetUrls();

        return null !== $slug && null !== $assetUrls ? $assetUrls->url($slug, $path) : null;
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function liveUrl(string $endpoint, array $params = []): ?string
    {
        $slug = self::callerExtensionSlug();
        $endpointUrls = self::$services?->endpointUrls();

        return null !== $slug && null !== $endpointUrls ? $endpointUrls->liveUrl($slug, $endpoint, $params) : null;
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function apiUrl(string $endpoint, array $params = []): ?string
    {
        $slug = self::callerExtensionSlug();
        $endpointUrls = self::$services?->endpointUrls();

        return null !== $slug && null !== $endpointUrls ? $endpointUrls->apiUrl($slug, $endpoint, $params) : null;
    }

    /**
     * @param array<string, mixed> $options
     * @return array{ok: bool, status: int|null, headers: array<string, list<string>>, body: string, json: mixed, error: string|null}
     */
    public static function httpRequest(string $method, string $url, mixed $payload = null, array $options = []): array
    {
        $slug = self::callerExtensionSlug();
        $httpRequests = self::$services?->httpRequests();

        return null !== $slug && null !== $httpRequests
            ? $httpRequests->request($slug, $method, $url, $payload, $options)
            : [
                'ok' => false,
                'status' => null,
                'headers' => [],
                'body' => '',
                'json' => null,
                'error' => 'invalid_extension',
            ];
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function log(string $level, string $message, array $context = []): bool
    {
        $slug = self::callerExtensionSlug();
        $logs = self::$services?->logs();

        return null !== $slug && null !== $logs && $logs->log($slug, $level, $message, $context);
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $options
     */
    public static function alert(string $level, string $message, array $parameters = [], array $options = []): bool
    {
        $slug = self::callerExtensionSlug();
        $alerts = self::$services?->alerts();

        return null !== $slug && null !== $alerts && $alerts->alert($slug, $level, $message, $parameters, $options);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    public static function lookup(string $type, string $identifier, array $options = []): ?array
    {
        $slug = self::callerExtensionSlug();
        $references = self::$services?->references();

        return null !== $slug && null !== $references ? $references->lookup($type, $identifier, $options) : null;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    public static function entity(string $uid, ?string $type = null, array $options = []): ?array
    {
        $slug = self::callerExtensionSlug();
        $references = self::$services?->references();

        return null !== $slug && null !== $references ? $references->entity($uid, $type, $options) : null;
    }

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public static function dbFetch(string $table, array $criteria = [], array $options = []): array
    {
        $slug = self::callerExtensionSlug();
        $databases = self::$services?->databases();

        return null !== $slug && null !== $databases ? $databases->fetch($slug, $table, $criteria, $options) : [];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function dbInsert(string $table, array $row): bool
    {
        $slug = self::callerExtensionSlug();
        $databases = self::$services?->databases();

        return null !== $slug && null !== $databases && $databases->insert($slug, $table, $row);
    }

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, mixed> $values
     */
    public static function dbUpdate(string $table, array $criteria, array $values): int
    {
        $slug = self::callerExtensionSlug();
        $databases = self::$services?->databases();

        return null !== $slug && null !== $databases ? $databases->update($slug, $table, $criteria, $values) : 0;
    }

    /**
     * @param array<string, mixed> $criteria
     */
    public static function dbDelete(string $table, array $criteria): int
    {
        $slug = self::callerExtensionSlug();
        $databases = self::$services?->databases();

        return null !== $slug && null !== $databases ? $databases->delete($slug, $table, $criteria) : 0;
    }

    /**
     * @internal test helper
     */
    public static function reset(): void
    {
        self::$services = null;
    }

    private static function callerExtensionSlug(): ?string
    {
        $services = self::$services;
        $projectDir = null !== $services ? $services->projectDir() : realpath(dirname(__DIR__, 3));
        $extensionsDir = self::normalizePath(is_string($projectDir) ? $projectDir : dirname(__DIR__, 3)).'/extensions/';

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = self::normalizePath((string) ($frame['file'] ?? ''));
            if ('' === $file || !str_starts_with($file, $extensionsDir)) {
                continue;
            }

            $relative = substr($file, strlen($extensionsDir));
            $segments = explode('/', $relative);
            $slug = $segments[0] ?? '';

            return ExtensionManifestSpec::isValidSlug($slug) ? $slug : null;
        }

        return null;
    }

    private static function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
