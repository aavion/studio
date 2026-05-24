<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\TestCase;

final class TestSuiteLifecycleTest extends TestCase
{
    public function testCleanupRemovesSuiteTemporaryRoot(): void
    {
        $root = TestSuiteLifecycle::temporaryRoot();
        $nestedDirectory = $root.'/nested';

        if (!is_dir($nestedDirectory)) {
            mkdir($nestedDirectory, 0777, true);
        }

        file_put_contents($nestedDirectory.'/fixture.txt', 'temporary');

        TestSuiteLifecycle::cleanup();

        self::assertDirectoryDoesNotExist($root);
    }

    public function testBootstrapCreatesTestDatabaseLockFile(): void
    {
        self::assertFileExists(dirname(__DIR__, 2).'/var/test-suite.lock');
    }
}
