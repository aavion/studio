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

    public function testItPreservesInheritedLocksByDefault(): void
    {
        if ('\\' === DIRECTORY_SEPARATOR) {
            self::markTestSkipped('POSIX file descriptor inheritance is not applicable on Windows.');
        }

        $root = $this->createTemporaryDirectory('detached-process-preserved-lock');
        $outputPath = $root.'/logs/process.log';
        $pidPath = $root.'/pids/process.pid';
        $lockPath = $root.'/phpunit.lock';
        $lock = fopen($lockPath, 'c');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX));

        try {
            $started = (new DetachedProcessStarter())->start(
                [PHP_BINARY, '-r', 'sleep(10);'],
                $root,
                $outputPath,
                $pidPath,
            );

            self::assertTrue($started);
            self::assertTrue($this->waitForFile($pidPath));

            fclose($lock);

            self::assertSame('locked', $this->lockState($lockPath));
        } finally {
            if (is_resource($lock)) {
                fclose($lock);
            }

            $this->stopPidFile($pidPath);
        }

        self::assertTrue($this->waitForLockState($lockPath, 'free'));
    }

    public function testItCanCloseInheritedLocksForPersistentDetachedChildren(): void
    {
        if ('\\' === DIRECTORY_SEPARATOR) {
            self::markTestSkipped('POSIX file descriptor inheritance is not applicable on Windows.');
        }

        $root = $this->createTemporaryDirectory('detached-process-closed-lock');
        $outputPath = $root.'/logs/process.log';
        $pidPath = $root.'/pids/process.pid';
        $lockPath = $root.'/phpunit.lock';
        $lock = fopen($lockPath, 'c');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX));

        try {
            $started = (new DetachedProcessStarter())->start(
                [PHP_BINARY, '-r', 'sleep(10);'],
                $root,
                $outputPath,
                $pidPath,
                closeInheritedFileDescriptors: true,
            );

            self::assertTrue($started);
            self::assertTrue($this->waitForFile($pidPath));

            fclose($lock);

            self::assertSame('free', $this->lockState($lockPath));
        } finally {
            if (is_resource($lock)) {
                fclose($lock);
            }

            $this->stopPidFile($pidPath);
        }
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

    private function waitForFile(string $path): bool
    {
        for ($attempt = 0; $attempt < 50; ++$attempt) {
            if (is_file($path)) {
                return true;
            }

            usleep(100_000);
        }

        return false;
    }

    private function lockState(string $path): string
    {
        $process = proc_open(
            [PHP_BINARY, '-r', '$f = fopen($argv[1], "c"); echo flock($f, LOCK_EX | LOCK_NB) ? "free" : "locked";', $path],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );
        self::assertIsResource($process);

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return trim((string) $output);
    }

    private function waitForLockState(string $path, string $expected): bool
    {
        for ($attempt = 0; $attempt < 50; ++$attempt) {
            if ($expected === $this->lockState($path)) {
                return true;
            }

            usleep(100_000);
        }

        return false;
    }

    private function stopPidFile(string $pidPath): void
    {
        if (!is_file($pidPath)) {
            return;
        }

        $pid = trim((string) file_get_contents($pidPath));
        if (function_exists('posix_kill') && 1 === preg_match('/^\d+$/', $pid)) {
            @posix_kill((int) $pid, 15);
        }
    }
}
