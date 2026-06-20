<?php

declare(strict_types=1);

namespace App\Core\Extension\Install;

use App\Core\Extension\ExtensionManifestSpec;
use InvalidArgumentException;

final readonly class ExtensionInstallFilesystem
{
    private const INSTALL_ID_PATTERN = '/^[a-f0-9]{24}$/';

    public function __construct(private string $projectDir)
    {
    }

    public function isValidInstallId(string $installId): bool
    {
        return 1 === preg_match(self::INSTALL_ID_PATTERN, $installId);
    }

    public function installRoot(string $environment, string $installId): string
    {
        if (!$this->isValidInstallId($installId)) {
            throw new InvalidArgumentException('Extension install id must be a 24-character lowercase hex token.');
        }

        return $this->projectDir
            .DIRECTORY_SEPARATOR.'var'
            .DIRECTORY_SEPARATOR.'cache'
            .DIRECTORY_SEPARATOR.$environment
            .DIRECTORY_SEPARATOR.'extension-installs'
            .DIRECTORY_SEPARATOR.$installId;
    }

    public function extensionTarget(string $extensionName): string
    {
        if ('system' === $extensionName || !ExtensionManifestSpec::isValidSlug($extensionName)) {
            throw new InvalidArgumentException('Extension target name must be a valid non-system extension slug.');
        }

        return $this->projectDir.DIRECTORY_SEPARATOR.'extensions'.DIRECTORY_SEPARATOR.$extensionName;
    }

    public function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new \RuntimeException(sprintf('Directory "%s" could not be created.', $path));
        }
    }

    public function prepareReplacement(string $extensionRoot, string $prepared): void
    {
        $this->removePath($prepared);
        $this->ensureDirectory(dirname($prepared));
        $this->copyDirectory($extensionRoot, $prepared);
    }

    public function swapPreparedExtension(string $prepared, string $target, string $backup): void
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

            if ($this->skipInstallCopy($relative)) {
                continue;
            }

            if ($item->isLink()) {
                throw new \RuntimeException(sprintf('Symlink "%s" must not be copied into an extension.', $relative));
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

    private function skipInstallCopy(string $relativePath): bool
    {
        $path = rtrim(str_replace('\\', '/', $relativePath), '/');

        return 'tests' === $path
            || str_starts_with($path, 'tests/')
            || 1 === preg_match('#^(?:\.git|\.hg|\.svn)(?:/|$)#', $path)
            || 1 === preg_match('#^\.(?:git|hg|svn).+#', $path)
            || 1 === preg_match('#^(?:\.github|\.idea|\.vscode)(?:/|$)#', $path)
            || in_array($path, ['.editorconfig'], true);
    }
}
