<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Filesystem\FileInventoryScanner;

final readonly class PackageInventoryInspector
{
    public function __construct(private FileInventoryScanner $fileInventoryScanner = new FileInventoryScanner())
    {
    }

    public function inspect(string $directory, int $depth): PackageInspection
    {
        $inventory = $this->fileInventoryScanner->scan($directory, $depth);
        $inspectableFiles = $inventory->filesWhere(static fn (string $path): bool => PackageFilePolicy::isInspectablePackagePath($path));
        $assetFiles = $this->filesWhere($inspectableFiles, static fn (string $path): bool => str_starts_with($path, 'assets/'));
        $privateAssetFiles = $this->filesWhere($inspectableFiles, static fn (string $path): bool => str_starts_with($path, 'private-assets/'));
        $assetValidationFiles = [...$assetFiles, ...$privateAssetFiles];
        $cssFiles = $this->filesWhere($assetValidationFiles, static fn (string $path): bool => str_ends_with($path, '.css'));
        $javaScriptFiles = $this->filesWhere($assetValidationFiles, static fn (string $path): bool => str_ends_with($path, '.js') || str_ends_with($path, '.mjs'));

        return new PackageInspection(
            $inventory->entries(),
            $this->filesWhere($inspectableFiles, static fn (string $path): bool => str_starts_with($path, 'templates/') && str_ends_with($path, '.twig')),
            $assetFiles,
            $this->filesWhere($inspectableFiles, static fn (string $path): bool => str_ends_with($path, '.php')),
            $this->filesWhere($inspectableFiles, static fn (string $path): bool => str_starts_with($path, 'src/') && str_ends_with($path, '.php')),
            $this->filesWhere($inspectableFiles, static fn (string $path): bool => str_ends_with($path, '.twig')),
            $this->filesWhere($inspectableFiles, static fn (string $path): bool => str_ends_with($path, '.json')),
            $this->filesWhere($inspectableFiles, static fn (string $path): bool => str_ends_with($path, '.yaml') || str_ends_with($path, '.yml')),
            $cssFiles,
            $javaScriptFiles,
            array_values(array_filter($assetFiles, static fn (string $path): bool => !in_array($path, [...$cssFiles, ...$javaScriptFiles], true))),
            $this->hasComposerDependencies($inventory->entries()),
            $this->hasNodeDependencies($inventory->entries()),
            $this->hasEnglishTranslations($inventory->entries()),
        );
    }

    /**
     * @param list<string> $files
     *
     * @return list<string>
     */
    private function filesWhere(array $files, callable $filter): array
    {
        return array_values(array_filter($files, $filter));
    }

    /**
     * @param list<string> $entries
     */
    private function hasComposerDependencies(array $entries): bool
    {
        return in_array('composer.json', $entries, true) && in_array('composer.lock', $entries, true);
    }

    /**
     * @param list<string> $entries
     */
    private function hasNodeDependencies(array $entries): bool
    {
        if (!in_array('assets/package.json', $entries, true)) {
            return false;
        }

        foreach (['assets/package-lock.json', 'assets/npm-shrinkwrap.json', 'assets/yarn.lock', 'assets/pnpm-lock.yaml', 'assets/bun.lock', 'assets/bun.lockb'] as $lockFile) {
            if (in_array($lockFile, $entries, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $entries
     */
    private function hasEnglishTranslations(array $entries): bool
    {
        foreach ($entries as $entry) {
            if (str_starts_with($entry, 'languages/en/')) {
                return true;
            }
        }

        return false;
    }
}
