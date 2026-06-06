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
        $assetFiles = $inventory->filesWhere(static fn (string $path): bool => str_starts_with($path, 'assets/'));
        $cssFiles = $inventory->filesWhere(static fn (string $path): bool => str_ends_with($path, '.css'));
        $javaScriptFiles = $inventory->filesWhere(static fn (string $path): bool => str_ends_with($path, '.js') || str_ends_with($path, '.mjs'));

        return new PackageInspection(
            $inventory->entries(),
            $inventory->filesWhere(static fn (string $path): bool => str_starts_with($path, 'templates/') && str_ends_with($path, '.twig')),
            $assetFiles,
            $inventory->filesWhere(static fn (string $path): bool => str_ends_with($path, '.php')),
            $inventory->filesWhere(static fn (string $path): bool => str_starts_with($path, 'src/') && str_ends_with($path, '.php')),
            $inventory->filesWhere(static fn (string $path): bool => str_ends_with($path, '.twig')),
            $inventory->filesWhere(static fn (string $path): bool => str_ends_with($path, '.json')),
            $inventory->filesWhere(static fn (string $path): bool => str_ends_with($path, '.yaml') || str_ends_with($path, '.yml')),
            $cssFiles,
            $javaScriptFiles,
            array_values(array_filter($assetFiles, static fn (string $path): bool => !in_array($path, [...$cssFiles, ...$javaScriptFiles], true))),
        );
    }
}
