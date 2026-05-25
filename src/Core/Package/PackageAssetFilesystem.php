<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Filesystem\PathGuard;
use RuntimeException;

final readonly class PackageAssetFilesystem
{
    public function __construct(
        private string $projectDir,
        private PathGuard $pathGuard = new PathGuard(),
    ) {
    }

    public function absolutePath(string $path): string
    {
        return rtrim($this->projectDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $this->pathGuard->relativePath($path));
    }

    public function isSafeDirectory(string $path): bool
    {
        $absolutePath = $this->absolutePath($path);

        return is_dir($absolutePath)
            && !is_link($absolutePath)
            && null === $this->pathGuard->symlinkAncestor($this->projectDir, $path);
    }

    public function pathExists(string $path): bool
    {
        $absolutePath = $this->absolutePath($path);

        return file_exists($absolutePath) || is_link($absolutePath);
    }

    public function ensureDirectory(string $path): void
    {
        $absolutePath = $this->absolutePath($path);

        if (is_link($absolutePath)) {
            throw new RuntimeException(sprintf('Directory "%s" must not be a symlink.', $path));
        }

        if (null !== $this->pathGuard->symlinkAncestor($this->projectDir, $path)) {
            throw new RuntimeException(sprintf('Directory "%s" must not be below a symlink.', $path));
        }

        if (file_exists($absolutePath) && !is_dir($absolutePath)) {
            throw new RuntimeException(sprintf('Directory "%s" exists as a file.', $path));
        }

        if (!is_dir($absolutePath) && !mkdir($absolutePath, 0775, true) && !is_dir($absolutePath)) {
            throw new RuntimeException(sprintf('Directory "%s" could not be created.', $path));
        }
    }

    public function ensureParentDirectory(string $path): void
    {
        $parent = dirname($this->pathGuard->relativePath($path));

        if ('.' === $parent) {
            return;
        }

        $this->ensureDirectory($parent);
    }

    public function readFile(string $path): string
    {
        $contents = file_get_contents($this->absolutePath($path));

        if (false === $contents) {
            throw new RuntimeException(sprintf('Package asset "%s" could not be read.', $path));
        }

        return $contents;
    }

    public function writeFile(string $path, string $contents): void
    {
        $absolutePath = $this->absolutePath($path);

        if (is_link($absolutePath)) {
            throw new RuntimeException(sprintf('Target file "%s" must not be a symlink.', $path));
        }

        if (is_dir($absolutePath)) {
            throw new RuntimeException(sprintf('Target file "%s" exists as a directory.', $path));
        }

        $this->ensureParentDirectory($path);

        if (false === file_put_contents($absolutePath, $contents, LOCK_EX)) {
            throw new RuntimeException(sprintf('Target file "%s" could not be written.', $path));
        }
    }

    public function removePath(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            if (!@unlink($path)) {
                throw new RuntimeException(sprintf('Path "%s" could not be removed.', $path));
            }

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);

        if (false === $entries) {
            throw new RuntimeException(sprintf('Directory "%s" could not be read.', $path));
        }

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $this->removePath($path.DIRECTORY_SEPARATOR.$entry);
        }

        if (!@rmdir($path)) {
            throw new RuntimeException(sprintf('Directory "%s" could not be removed.', $path));
        }
    }
}
