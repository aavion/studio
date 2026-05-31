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

    public function prepareMirrorDirectory(): string
    {
        $rootPath = 'assets/packages';
        $root = $this->filesystem->absolutePath($rootPath);

        if (is_link($root)) {
            throw new RuntimeException(sprintf('Package asset mirror "%s" must not be a symlink.', $rootPath));
        }

        if (file_exists($root) && !is_dir($root)) {
            throw new RuntimeException(sprintf('Package asset mirror "%s" must be a directory.', $rootPath));
        }

        $stagingPath = 'assets/.packages.tmp-'.bin2hex(random_bytes(8));
        $this->filesystem->ensureDirectory($stagingPath);

        foreach (['.gitignore', 'README.md'] as $preservedFile) {
            $source = $root.DIRECTORY_SEPARATOR.$preservedFile;

            if (!is_file($source) || is_link($source)) {
                continue;
            }

            $target = $this->filesystem->absolutePath($stagingPath.'/'.$preservedFile);

            if (!copy($source, $target)) {
                throw new RuntimeException(sprintf('Package asset mirror file "%s" could not be staged.', $preservedFile));
            }
        }

        return $stagingPath;
    }

    public function commitMirrorDirectory(string $stagingPath): void
    {
        $rootPath = 'assets/packages';
        $root = $this->filesystem->absolutePath($rootPath);
        $staging = $this->filesystem->absolutePath($stagingPath);
        $backupPath = 'assets/.packages.backup-'.bin2hex(random_bytes(8));
        $backup = $this->filesystem->absolutePath($backupPath);

        if (!is_dir($staging) || is_link($staging)) {
            throw new RuntimeException(sprintf('Package asset mirror staging directory "%s" is not valid.', $stagingPath));
        }

        if (file_exists($root) || is_link($root)) {
            if (!@rename($root, $backup)) {
                throw new RuntimeException(sprintf('Package asset mirror "%s" could not be moved aside.', $rootPath));
            }
        }

        if (!@rename($staging, $root)) {
            if (is_dir($backup) && !file_exists($root)) {
                @rename($backup, $root);
            }

            throw new RuntimeException(sprintf('Package asset mirror "%s" could not be replaced.', $rootPath));
        }

        if (is_dir($backup)) {
            $this->filesystem->removePath($backup);
        }
    }

    public function discardMirrorDirectory(string $stagingPath): void
    {
        $staging = $this->filesystem->absolutePath($stagingPath);

        if (file_exists($staging) || is_link($staging)) {
            $this->filesystem->removePath($staging);
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

    public function mirrorAsset(PackageAssetSyncPackage $package, string $packageAssetRoot, string $assetFile, string $mirrorRoot = 'assets/packages'): string
    {
        $targetPath = 'assets/packages/'.$package->identifier().'/'.substr($assetFile, strlen('assets/'));
        $stagedTargetPath = rtrim($mirrorRoot, '/').'/'.$package->identifier().'/'.substr($assetFile, strlen('assets/'));
        $sourcePath = $package->directory().'/'.$assetFile;
        $absoluteTarget = $this->filesystem->absolutePath($stagedTargetPath);
        $this->filesystem->ensureParentDirectory($stagedTargetPath);

        if (str_ends_with($assetFile, '.css')) {
            $contents = $this->filesystem->readFile($sourcePath);
            $this->filesystem->writeFile($stagedTargetPath, $this->pathRewriter->rewriteCss(
                $contents,
                $sourcePath,
                $packageAssetRoot,
                'assets/packages/'.$package->identifier(),
            ));

            return $targetPath;
        }

        if (str_ends_with($assetFile, '.js') || str_ends_with($assetFile, '.mjs')) {
            $contents = $this->filesystem->readFile($sourcePath);
            $this->filesystem->writeFile($stagedTargetPath, $this->pathRewriter->rewriteJavaScript(
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
