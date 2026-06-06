<?php

declare(strict_types=1);

namespace App\Core\Package\Install;

final readonly class PackageInstallFilesystem
{
    public function __construct(private string $projectDir)
    {
    }

    public function installRoot(string $environment, string $installId): string
    {
        return $this->projectDir
            .DIRECTORY_SEPARATOR.'var'
            .DIRECTORY_SEPARATOR.'cache'
            .DIRECTORY_SEPARATOR.$environment
            .DIRECTORY_SEPARATOR.'package-installs'
            .DIRECTORY_SEPARATOR.$installId;
    }

    public function packageTarget(string $packageName): string
    {
        return $this->projectDir.DIRECTORY_SEPARATOR.'packages'.DIRECTORY_SEPARATOR.$packageName;
    }

    public function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new \RuntimeException(sprintf('Directory "%s" could not be created.', $path));
        }
    }

    public function prepareReplacement(string $packageRoot, string $prepared): void
    {
        $this->removePath($prepared);
        $this->ensureDirectory(dirname($prepared));
        $this->copyDirectory($packageRoot, $prepared);
    }

    public function swapPreparedPackage(string $prepared, string $target, string $backup): void
    {
        $this->removePath($backup);
        $this->ensureDirectory(dirname($backup));

        if ($this->pathExists($target)) {
            $this->movePath($target, $backup);
        }

        try {
            $this->ensureDirectory(dirname($target));
            $this->movePath($prepared, $target);
        } catch (\Throwable $error) {
            $this->removePath($target);

            if ($this->pathExists($backup)) {
                $this->movePath($backup, $target);
            }

            throw $error;
        }
    }

    public function movePath(string $source, string $target): void
    {
        if (@rename($source, $target)) {
            return;
        }

        if (is_dir($source)) {
            $this->copyDirectory($source, $target);
            $this->removePath($source);

            return;
        }

        $this->ensureDirectory(dirname($target));
        if (!copy($source, $target)) {
            throw new \RuntimeException(sprintf('Path "%s" could not be moved.', basename($source)));
        }

        unlink($source);
    }

    public function pathExists(string $path): bool
    {
        return file_exists($path) || is_link($path);
    }

    public function copyDirectory(string $source, string $target): void
    {
        $this->ensureDirectory($target);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }

            $relative = substr($item->getPathname(), strlen($source) + 1);
            $destination = $target.DIRECTORY_SEPARATOR.$relative;

            if ($item->isLink()) {
                throw new \RuntimeException(sprintf('Symlink "%s" must not be copied into a package.', $relative));
            }

            if ($item->isDir()) {
                $this->ensureDirectory($destination);
                continue;
            }

            $this->ensureDirectory(dirname($destination));
            if (!copy($item->getPathname(), $destination)) {
                throw new \RuntimeException(sprintf('File "%s" could not be copied.', $relative));
            }
        }
    }

    public function removePath(string $path): void
    {
        if (!$this->pathExists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            $this->removeFileOrLink($path);

            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }

            $item->isDir() && !$item->isLink()
                ? rmdir($item->getPathname())
                : $this->removeFileOrLink($item->getPathname());
        }

        rmdir($path);
    }

    public function firstSymlinkPath(string $root): ?string
    {
        if (is_link($root)) {
            return $root;
        }

        if (!is_dir($root)) {
            return null;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item instanceof \SplFileInfo && $item->isLink()) {
                return $item->getPathname();
            }
        }

        return null;
    }

    public function relativePath(string $path): string
    {
        $projectDir = rtrim(str_replace('\\', '/', $this->projectDir), '/');
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $projectDir.'/') ? substr($path, strlen($projectDir) + 1) : $path;
    }

    private function removeFileOrLink(string $path): void
    {
        if ('\\' === DIRECTORY_SEPARATOR && @rmdir($path)) {
            return;
        }

        @unlink($path);
    }
}
