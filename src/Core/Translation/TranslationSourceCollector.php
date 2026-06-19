<?php

declare(strict_types=1);

namespace App\Core\Translation;

use App\Core\Filesystem\PathGuard;
use App\Core\Extension\ExtensionAssetSyncTarget;

final readonly class TranslationSourceCollector
{
    private const CORE_SOURCE_DIRECTORY = 'translations/languages';

    public function __construct(
        private string $projectDir,
        private PathGuard $pathGuard = new PathGuard(),
    ) {
    }

    /**
     * @param list<ExtensionAssetSyncTarget> $extensions
     *
     * @return list<array{locale: string, path: string}>
     */
    public function sources(array $extensions): array
    {
        return array_merge($this->coreSources(), $this->extensionSources($extensions));
    }

    /**
     * @param iterable<ExtensionAssetSyncTarget> $extensions
     *
     * @return list<ExtensionAssetSyncTarget>
     */
    public function sortedExtensions(iterable $extensions): array
    {
        $sorted = [];

        foreach ($extensions as $extension) {
            $sorted[] = $extension;
        }

        usort($sorted, static fn (ExtensionAssetSyncTarget $left, ExtensionAssetSyncTarget $right): int => $left->identifier() <=> $right->identifier());

        return $sorted;
    }

    public function relativeSourcePath(string $path): string
    {
        $prefix = rtrim($this->projectDir, DIRECTORY_SEPARATOR.'/\\').DIRECTORY_SEPARATOR;

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
     * @param list<ExtensionAssetSyncTarget> $extensions
     *
     * @return list<array{locale: string, path: string}>
     */
    private function extensionSources(array $extensions): array
    {
        $sources = [];

        foreach ($extensions as $extension) {
            $languageRoot = $extension->directory().'/languages';
            if (!$this->isSafeExtensionLanguageRoot($languageRoot)) {
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

    private function isSafeExtensionLanguageRoot(string $path): bool
    {
        if (!$this->pathGuard->isRelativePath($path)) {
            return false;
        }

        $relativePath = $this->pathGuard->relativePath($path);
        if (!str_starts_with($relativePath, 'extensions/') || !str_ends_with($relativePath, '/languages')) {
            return false;
        }

        return is_dir($this->absolutePath($relativePath));
    }

    private function absolutePath(string $path): string
    {
        return $this->projectDir.'/'.$this->pathGuard->relativePath($path);
    }
}
