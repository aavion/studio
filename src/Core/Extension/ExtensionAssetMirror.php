<?php

declare(strict_types=1);

namespace App\Core\Extension;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final readonly class ExtensionAssetMirror
{
    public function __construct(
        private ExtensionAssetFilesystem $filesystem,
        private ExtensionAssetPathRewriter $pathRewriter = new ExtensionAssetPathRewriter(),
    ) {
    }

    public function prepareMirrorDirectory(): string
    {
        $rootPath = 'assets/extensions';
        $root = $this->filesystem->absolutePath($rootPath);

        if (is_link($root)) {
            throw new RuntimeException(sprintf('Extension asset mirror "%s" must not be a symlink.', $rootPath));
        }

        if (file_exists($root) && !is_dir($root)) {
            throw new RuntimeException(sprintf('Extension asset mirror "%s" must be a directory.', $rootPath));
        }

        $stagingPath = 'assets/.extensions.tmp-'.bin2hex(random_bytes(8));

        try {
            $this->filesystem->ensureDirectory($stagingPath);

            foreach (['.gitignore', 'README.md'] as $preservedFile) {
                $source = $root.DIRECTORY_SEPARATOR.$preservedFile;

                if (!is_file($source) || is_link($source)) {
                    continue;
                }

                $target = $this->filesystem->absolutePath($stagingPath.'/'.$preservedFile);

                if (!copy($source, $target)) {
                    throw new RuntimeException(sprintf('Extension asset mirror file "%s" could not be staged.', $preservedFile));
                }
            }
        } catch (\Throwable $error) {
            $this->discardMirrorDirectory($stagingPath);

            throw $error;
        }

        return $stagingPath;
    }

    public function commitMirrorDirectory(string $stagingPath): void
    {
        $rootPath = 'assets/extensions';
        $root = $this->filesystem->absolutePath($rootPath);
        $staging = $this->filesystem->absolutePath($stagingPath);
        $backupPath = 'assets/.extensions.backup-'.bin2hex(random_bytes(8));
        $backup = $this->filesystem->absolutePath($backupPath);

        if (!is_dir($staging) || is_link($staging)) {
            throw new RuntimeException(sprintf('Extension asset mirror staging directory "%s" is not valid.', $stagingPath));
        }

        if (file_exists($root) || is_link($root)) {
            if (!@rename($root, $backup)) {
                throw new RuntimeException(sprintf('Extension asset mirror "%s" could not be moved aside.', $rootPath));
            }
        }

        if (!@rename($staging, $root)) {
            if (is_dir($backup) && !file_exists($root)) {
                @rename($backup, $root);
            }

            throw new RuntimeException(sprintf('Extension asset mirror "%s" could not be replaced.', $rootPath));
        }

        if (is_dir($backup)) {
            try {
                $this->filesystem->removePath($backup);
            } catch (RuntimeException) {
                return;
            }
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
    public function assetFiles(string $extensionAssetRoot): array
    {
        $absoluteRoot = $this->filesystem->absolutePath($extensionAssetRoot);
        $files = [];

        if (!$this->filesystem->isSafeDirectory($extensionAssetRoot)) {
            throw new RuntimeException(sprintf('Extension asset root "%s" is not a safe directory.', $extensionAssetRoot));
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

    public function mirrorAsset(ExtensionAssetSyncTarget $extension, string $extensionAssetRoot, string $assetFile, string $mirrorRoot = 'assets/extensions'): string
    {
        $targetPath = 'assets/extensions/'.$extension->identifier().'/'.substr($assetFile, strlen('assets/'));
        $stagedTargetPath = rtrim($mirrorRoot, '/').'/'.$extension->identifier().'/'.substr($assetFile, strlen('assets/'));
        $sourcePath = $extension->directory().'/'.$assetFile;
        $absoluteTarget = $this->filesystem->absolutePath($stagedTargetPath);
        $this->filesystem->ensureParentDirectory($stagedTargetPath);

        if (str_ends_with($assetFile, '.css')) {
            $contents = $this->filesystem->readFile($sourcePath);
            $this->filesystem->writeFile($stagedTargetPath, $this->pathRewriter->rewriteCss(
                $contents,
                $sourcePath,
                $extensionAssetRoot,
                'assets/extensions/'.$extension->identifier(),
            ));

            return $targetPath;
        }

        if (str_ends_with($assetFile, '.js') || str_ends_with($assetFile, '.mjs')) {
            $contents = $this->filesystem->readFile($sourcePath);
            $this->filesystem->writeFile($stagedTargetPath, $this->pathRewriter->rewriteJavaScript(
                $contents,
                $sourcePath,
                $extensionAssetRoot,
                $targetPath,
                'assets/extensions/'.$extension->identifier(),
            ));

            return $targetPath;
        }

        if (!copy($this->filesystem->absolutePath($sourcePath), $absoluteTarget)) {
            throw new RuntimeException(sprintf('Extension asset "%s" could not be copied to "%s".', $sourcePath, $targetPath));
        }

        return $targetPath;
    }
}
