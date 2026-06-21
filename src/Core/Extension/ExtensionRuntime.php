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
