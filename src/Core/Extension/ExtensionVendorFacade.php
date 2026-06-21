<?php

declare(strict_types=1);

namespace App\Core\Extension;

use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;
use Throwable;

final class ExtensionVendorFacade
{
    /**
     * @var array<string, true>
     */
    private static array $loadedPackages = [];

    /**
     * @var array<string, list<string>>
     */
    private static array $registeredPrefixes = [];

    private static bool $registered = false;

    private function __construct()
    {
    }

    public static function requireVendor(string $package): bool
    {
        $package = strtolower(trim($package));
        if (!ExtensionVendorPackageMetadata::isPackageName($package)) {
            return false;
        }

        $extensionRoot = self::extensionRootForCaller();
        if (null === $extensionRoot) {
            return false;
        }

        if (isset(self::$loadedPackages[$package]) || self::composerPackageAvailable($package)) {
            self::$loadedPackages[$package] = true;

            return true;
        }

        $vendorRoot = $extensionRoot.'/vendor';
        $prefixes = ExtensionVendorPackageMetadata::psr4Prefixes($vendorRoot, $package);
        if ([] === $prefixes || !self::registerPrefixes($package, $prefixes)) {
            return false;
        }

        self::$loadedPackages[$package] = true;

        return true;
    }

    /**
     * @internal test helper
     */
    public static function reset(): void
    {
        self::$loadedPackages = [];
        self::$registeredPrefixes = [];
    }

    private static function composerPackageAvailable(string $package): bool
    {
        try {
            return class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($package);
        } catch (Throwable) {
            return false;
        }
    }

    private static function extensionRootForCaller(): ?string
    {
        $projectDir = self::projectDir();
        $extensionsDir = $projectDir.'/extensions/';

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = self::normalizePath((string) ($frame['file'] ?? ''));
            if ('' === $file || !str_starts_with($file, $extensionsDir)) {
                continue;
            }

            $relative = substr($file, strlen($extensionsDir));
            $segments = explode('/', $relative);
            $slug = $segments[0] ?? '';
            if ('' === $slug || !ExtensionManifestSpec::isValidSlug($slug)) {
                return null;
            }

            return $extensionsDir.$slug;
        }

        return null;
    }

    /**
     * @param array<string, list<string>> $prefixes
     */
    private static function registerPrefixes(string $package, array $prefixes): bool
    {
        foreach ($prefixes as $prefix => $directories) {
            $known = self::$registeredPrefixes[$prefix] ?? null;
            if (null !== $known && $known !== $directories) {
                return false;
            }

            if (self::conflictsWithComposerPrefix($prefix, $directories)) {
                return false;
            }
        }

        foreach ($prefixes as $prefix => $directories) {
            self::$registeredPrefixes[$prefix] = $directories;
        }

        if (!self::$registered) {
            spl_autoload_register(self::load(...));
            self::$registered = true;
        }

        self::$loadedPackages[$package] = true;

        return true;
    }

    private static function load(string $class): void
    {
        foreach (self::$registeredPrefixes as $prefix => $directories) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }

            $relativeClass = substr($class, strlen($prefix));
            if ('' === $relativeClass || str_contains($relativeClass, "\0")) {
                return;
            }

            foreach ($directories as $directory) {
                $path = $directory.'/'.str_replace('\\', '/', $relativeClass).'.php';
                if (is_file($path)) {
                    require $path;

                    return;
                }
            }

            return;
        }
    }

    private static function projectDir(): string
    {
        $projectDir = realpath(dirname(__DIR__, 3));

        return self::normalizePath(is_string($projectDir) ? $projectDir : dirname(__DIR__, 3));
    }

    /**
     * @param list<string> $directories
     */
    private static function conflictsWithComposerPrefix(string $prefix, array $directories): bool
    {
        foreach (spl_autoload_functions() as $loader) {
            if (!is_array($loader) || !$loader[0] instanceof ClassLoader) {
                continue;
            }

            foreach ($loader[0]->getPrefixesPsr4() as $existingPrefix => $existingDirectories) {
                if (!self::prefixesOverlap($prefix, (string) $existingPrefix)) {
                    continue;
                }

                if ($prefix === $existingPrefix && self::sameDirectories($directories, $existingDirectories)) {
                    continue;
                }

                return true;
            }
        }

        return false;
    }

    private static function prefixesOverlap(string $left, string $right): bool
    {
        return str_starts_with($left, $right) || str_starts_with($right, $left);
    }

    /**
     * @param list<string> $left
     * @param list<string> $right
     */
    private static function sameDirectories(array $left, array $right): bool
    {
        $left = array_map(self::normalizePath(...), $left);
        $right = array_map(self::normalizePath(...), $right);
        sort($left);
        sort($right);

        return $left === $right;
    }

    private static function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
