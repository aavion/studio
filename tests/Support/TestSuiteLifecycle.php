<?php

declare(strict_types=1);

namespace App\Tests\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class TestSuiteLifecycle
{
    public static function initialize(): void
    {
    }

    public static function cleanup(): void
    {
        self::removeDirectory(self::temporaryRoot());
    }

    public static function temporaryRoot(): string
    {
        return sys_get_temp_dir().'/studio-test-suite';
    }

    private static function removeDirectory(string $directory): void
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

            if ($file->isDir()) {
                rmdir($file->getPathname());
                continue;
            }

            unlink($file->getPathname());
        }

        rmdir($directory);
    }
}
