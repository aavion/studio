<?php

declare(strict_types=1);

namespace App\Core\Extension;

final class ExtensionCacheRuntime
{
    private static ?ExtensionCacheInterface $cache = null;
    private static ?string $projectDir = null;

    private function __construct()
    {
    }

    public static function configure(ExtensionCacheInterface $cache, string $projectDir): void
    {
        self::$cache = $cache;
        self::$projectDir = self::normalizePath($projectDir);
    }

    public static function set(string $key, mixed $value, int $ttlSeconds = ExtensionCacheInterface::DEFAULT_TTL_SECONDS): bool
    {
        $slug = self::callerExtensionSlug();

        return null !== $slug && null !== self::$cache && self::$cache->set($slug, $key, $value, $ttlSeconds);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $slug = self::callerExtensionSlug();

        return null !== $slug && null !== self::$cache ? self::$cache->get($slug, $key, $default) : $default;
    }

    public static function delete(string $key): bool
    {
        $slug = self::callerExtensionSlug();

        return null !== $slug && null !== self::$cache && self::$cache->delete($slug, $key);
    }

    /**
     * @internal test helper
     */
    public static function reset(): void
    {
        self::$cache = null;
        self::$projectDir = null;
    }

    private static function callerExtensionSlug(): ?string
    {
        $projectDir = self::$projectDir ?? realpath(dirname(__DIR__, 3));
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
