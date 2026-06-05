<?php

declare(strict_types=1);

namespace App\Core\Translation;

use App\Core\Filesystem\PathGuard;
use App\Core\Package\PackageAssetSyncPackage;

final readonly class TranslationSourceCollector
{
    private const CORE_SOURCE_DIRECTORY = 'translations/languages';

    public function __construct(
        private string $projectDir,
        private PathGuard $pathGuard = new PathGuard(),
    ) {
    }

    /**
     * @param list<PackageAssetSyncPackage> $packages
     *
     * @return list<array{locale: string, path: string}>
     */
    public function sources(array $packages): array
    {
        return array_merge($this->coreSources(), $this->packageSources($packages));
    }

    /**
     * @param iterable<PackageAssetSyncPackage> $packages
     *
     * @return list<PackageAssetSyncPackage>
     */
    public function sortedPackages(iterable $packages): array
    {
        $sorted = [];

        foreach ($packages as $package) {
            $sorted[] = $package;
        }

        usort($sorted, static fn (PackageAssetSyncPackage $left, PackageAssetSyncPackage $right): int => $left->identifier() <=> $right->identifier());

        return $sorted;
    }

    public function relativeSourcePath(string $path): string
    {
        $prefix = rtrim($this->projectDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }

    /**
     * @return list<array{locale: string, path: string}>
     */
    private function coreSources(): array
    {
        return $this->sourcesFromLanguageRoot(self::CORE_SOURCE_DIRECTORY);
    }

    /**
     * @param list<PackageAssetSyncPackage> $packages
     *
     * @return list<array{locale: string, path: string}>
     */
    private function packageSources(array $packages): array
    {
        $sources = [];

        foreach ($packages as $package) {
            $languageRoot = $package->directory().'/languages';
            if (!$this->isSafePackageLanguageRoot($languageRoot)) {
                continue;
            }

            $sources = array_merge($sources, $this->sourcesFromLanguageRoot($languageRoot));
        }

        return $sources;
    }

    /**
     * @return list<array{locale: string, path: string}>
     */
    private function sourcesFromLanguageRoot(string $relativeRoot): array
    {
        if (!$this->pathGuard->isRelativePath($relativeRoot)) {
            return [];
        }

        $root = $this->absolutePath($relativeRoot);
        if (!is_dir($root)) {
            return [];
        }

        $sources = [];
        foreach (glob($root.'/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $locale = basename($directory);
            if (1 !== preg_match('/^[a-z][a-z0-9]*(?:[_-][A-Za-z0-9]+)*$/', $locale)) {
                continue;
            }

            foreach (glob($directory.'/*.yaml') ?: [] as $path) {
                $sources[] = [
                    'locale' => $locale,
                    'path' => $path,
                ];
            }
        }

        usort($sources, static fn (array $left, array $right): int => [$left['locale'], $left['path']] <=> [$right['locale'], $right['path']]);

        return $sources;
    }

    private function isSafePackageLanguageRoot(string $path): bool
    {
        if (!$this->pathGuard->isRelativePath($path)) {
            return false;
        }

        $relativePath = $this->pathGuard->relativePath($path);
        if (!str_starts_with($relativePath, 'packages/') || !str_ends_with($relativePath, '/languages')) {
            return false;
        }

        return is_dir($this->absolutePath($relativePath));
    }

    private function absolutePath(string $path): string
    {
        return $this->projectDir.'/'.$this->pathGuard->relativePath($path);
    }
}
