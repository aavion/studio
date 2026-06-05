<?php

declare(strict_types=1);

namespace App\Tests\Core\Process;

use App\Core\Process\DetachedProcessStarter;
use App\Tests\Support\FilesystemTestHelper;
use PHPUnit\Framework\TestCase;

final class DetachedProcessStarterTest extends TestCase
{
    use FilesystemTestHelper;

    public function testItStartsDetachedProcessesWithFilteredEnvironmentAndOutputFiles(): void
    {
        $root = $this->createTemporaryDirectory('detached-process');
        $outputPath = $root.'/logs/process.log';
        $pidPath = $root.'/pids/process.pid';

        $started = (new DetachedProcessStarter())->start(
            [PHP_BINARY, '-r', 'echo getenv("APP_ENV") ?: "missing";'],
            $root,
            $outputPath,
            $pidPath,
            ['APP_ENV' => 'detached-test'],
        );

        self::assertTrue($started);
        self::assertTrue($this->waitForFileContains($outputPath, 'detached-test'));
        self::assertFileExists($pidPath);
    }

    private function waitForFileContains(string $path, string $needle): bool
    {
        for ($attempt = 0; $attempt < 50; ++$attempt) {
            if (is_file($path) && str_contains((string) file_get_contents($path), $needle)) {
                return true;
            }

            usleep(100_000);
        }

        return false;
    }
}
