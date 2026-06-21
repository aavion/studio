<?php

declare(strict_types=1);

namespace App\Core\Extension;

use Throwable;

final readonly class ExtensionVendorPackageMetadata
{
    private function __construct()
    {
    }

    /**
     * @return array<string, list<string>>
     */
    public static function psr4Prefixes(string $vendorRoot, string $package): array
    {
        $metadata = self::packageMetadata($vendorRoot, $package);
        if (null === $metadata) {
            return [];
        }

        $autoload = $metadata['autoload'] ?? null;
        $psr4 = is_array($autoload) ? ($autoload['psr-4'] ?? null) : null;
        if (!is_array($psr4)) {
            return [];
        }

        $packageDirectory = self::packageDirectory($vendorRoot, $metadata);
        if (null === $packageDirectory) {
            return [];
        }

        $prefixes = [];
        foreach ($psr4 as $prefix => $paths) {
            if (!self::isPsr4Prefix((string) $prefix)) {
                return [];
            }

            $paths = is_array($paths) ? $paths : [$paths];
            foreach ($paths as $path) {
                if (!is_string($path) || '' === trim($path) || str_contains($path, "\0")) {
                    return [];
                }

                $directory = realpath($packageDirectory.'/'.str_replace('\\', '/', $path));
                if (!is_string($directory) || !is_dir($directory) || !self::insideDirectory($directory, $vendorRoot)) {
                    return [];
                }

                $prefixes[(string) $prefix][] = self::normalizePath($directory);
            }
        }

        return $prefixes;
    }

    public static function isPackageName(string $package): bool
    {
        return 1 === preg_match('/^[a-z0-9](?:[a-z0-9_.-]*[a-z0-9])?\/[a-z0-9](?:[a-z0-9_.-]*[a-z0-9])?$/', $package);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function packageMetadata(string $vendorRoot, string $package): ?array
    {
        $installedPath = $vendorRoot.'/composer/installed.json';
        if (!is_file($installedPath)) {
            return null;
        }

        try {
            $metadata = json_decode((string) file_get_contents($installedPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        $packages = is_array($metadata) && isset($metadata['packages']) && is_array($metadata['packages'])
            ? $metadata['packages']
            : $metadata;

        if (!is_array($packages)) {
            return null;
        }

        foreach ($packages as $candidate) {
            if (is_array($candidate) && strtolower((string) ($candidate['name'] ?? '')) === $package) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private static function packageDirectory(string $vendorRoot, array $metadata): ?string
    {
        $installPath = $metadata['install-path'] ?? null;
        if (is_string($installPath) && '' !== trim($installPath) && !str_contains($installPath, "\0")) {
            $directory = realpath($vendorRoot.'/composer/'.str_replace('\\', '/', $installPath));

            return is_string($directory) && self::insideDirectory($directory, $vendorRoot) ? self::normalizePath($directory) : null;
        }

        $packageName = $metadata['name'] ?? null;
        if (!is_string($packageName) || !self::isPackageName($packageName)) {
            return null;
        }

        $directory = realpath($vendorRoot.'/'.$packageName);

        return is_string($directory) && self::insideDirectory($directory, $vendorRoot) ? self::normalizePath($directory) : null;
    }

    private static function isPsr4Prefix(string $prefix): bool
    {
        return 1 === preg_match('/^[A-Z][A-Za-z0-9_]*(?:\\\\[A-Z][A-Za-z0-9_]*)*\\\\$/', $prefix);
    }

    private static function insideDirectory(string $path, string $directory): bool
    {
        $path = self::normalizePath($path);
        $directory = rtrim(self::normalizePath($directory), '/').'/';

        return str_starts_with($path.'/', $directory);
    }

    private static function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
