<?php

declare(strict_types=1);

namespace App\Tests\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

trait FilesystemTestHelper
{
    private function createTemporaryDirectory(string $prefix): string
    {
        $root = TestSuiteLifecycle::temporaryRoot();

        if (!is_dir($root)) {
            mkdir($root, 0777, true);
        }

        $directory = $root.'/'.$prefix.'-'.bin2hex(random_bytes(6));
        mkdir($directory, 0777, true);

        return $directory;
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function fixturePath(string $relativePath = ''): string
    {
        $path = $this->projectRoot().'/tests/Fixtures';
        $relativePath = trim($relativePath, '/');

        if ('' === $relativePath) {
            return $path;
        }

        return $path.'/'.$relativePath;
    }

    private function writeTestFile(string $root, string $relativePath, string $contents): void
    {
        $path = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, $contents);
    }

    private function createSymlinkOrSkip(string $target, string $link): void
    {
        if (!@symlink($target, $link)) {
            self::markTestSkipped('Symbolic links are not available in this environment.');
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }

            if ($file->isLink() || is_link($file->getPathname())) {
                unlink($file->getPathname());
                continue;
            }

            if ($file->isDir()) {
                rmdir($file->getPathname());
                continue;
            }

            unlink($file->getPathname());
        }

        rmdir($directory);
    }
}
