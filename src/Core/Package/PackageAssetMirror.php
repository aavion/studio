<?php

declare(strict_types=1);

namespace App\Core\Package;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final readonly class PackageAssetMirror
{
    public function __construct(
        private PackageAssetFilesystem $filesystem,
        private PackageAssetPathRewriter $pathRewriter = new PackageAssetPathRewriter(),
    ) {
    }

    public function resetMirrorDirectory(): void
    {
        $rootPath = 'assets/packages';
        $root = $this->filesystem->absolutePath($rootPath);

        if (is_link($root)) {
            throw new RuntimeException(sprintf('Package asset mirror "%s" must not be a symlink.', $rootPath));
        }

        if (file_exists($root) && !is_dir($root)) {
            throw new RuntimeException(sprintf('Package asset mirror "%s" must be a directory.', $rootPath));
        }

        $this->filesystem->ensureDirectory($rootPath);
        $entries = scandir($root);

        if (false === $entries) {
            throw new RuntimeException(sprintf('Package asset mirror "%s" cannot be read.', $rootPath));
        }

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry || '.gitignore' === $entry || 'README.md' === $entry) {
                continue;
            }

            $this->filesystem->removePath($root.DIRECTORY_SEPARATOR.$entry);

            if (file_exists($root.DIRECTORY_SEPARATOR.$entry) || is_link($root.DIRECTORY_SEPARATOR.$entry)) {
                throw new RuntimeException(sprintf('Package asset mirror entry "%s" could not be removed.', 'assets/packages/'.$entry));
            }
        }
    }

    /**
     * @return list<string>
     */
    public function assetFiles(string $packageAssetRoot): array
    {
        $absoluteRoot = $this->filesystem->absolutePath($packageAssetRoot);
        $files = [];

        if (!$this->filesystem->isSafeDirectory($packageAssetRoot)) {
            throw new RuntimeException(sprintf('Package asset root "%s" is not a safe directory.', $packageAssetRoot));
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absoluteRoot, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($absoluteRoot) + 1));
            $files[] = 'assets/'.$relative;
        }

        sort($files);

        return $files;
    }

    public function mirrorAsset(PackageAssetSyncPackage $package, string $packageAssetRoot, string $assetFile): string
    {
        $targetPath = 'assets/packages/'.$package->identifier().'/'.substr($assetFile, strlen('assets/'));
        $sourcePath = $package->directory().'/'.$assetFile;
        $absoluteTarget = $this->filesystem->absolutePath($targetPath);
        $this->filesystem->ensureParentDirectory($targetPath);

        if (str_ends_with($assetFile, '.css')) {
            $contents = $this->filesystem->readFile($sourcePath);
            $this->filesystem->writeFile($targetPath, $this->pathRewriter->rewriteCss(
                $contents,
                $sourcePath,
                $packageAssetRoot,
                'assets/packages/'.$package->identifier(),
            ));

            return $targetPath;
        }

        if (str_ends_with($assetFile, '.js') || str_ends_with($assetFile, '.mjs')) {
            $contents = $this->filesystem->readFile($sourcePath);
            $this->filesystem->writeFile($targetPath, $this->pathRewriter->rewriteJavaScript(
                $contents,
                $sourcePath,
                $packageAssetRoot,
                $targetPath,
                'assets/packages/'.$package->identifier(),
            ));

            return $targetPath;
        }

        if (!copy($this->filesystem->absolutePath($sourcePath), $absoluteTarget)) {
            throw new RuntimeException(sprintf('Package asset "%s" could not be copied to "%s".', $sourcePath, $targetPath));
        }

        return $targetPath;
    }
}
