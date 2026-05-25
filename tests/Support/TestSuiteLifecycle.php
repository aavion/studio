<?php

declare(strict_types=1);

namespace App\Tests\Support;

use RuntimeException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class TestSuiteLifecycle
{
    private static mixed $testDatabaseLock = null;

    public static function initialize(): void
    {
        self::acquireTestDatabaseLock(dirname(__DIR__, 2));
        self::initializeTestDatabase();
    }

    public static function cleanup(): void
    {
        self::removeDirectory(self::temporaryRoot());
    }

    public static function temporaryRoot(): string
    {
        return sys_get_temp_dir().'/studio-test-suite';
    }

    private static function initializeTestDatabase(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $testVarDirectory = $projectRoot.'/var/test';

        self::removeDirectory($testVarDirectory);
        self::removeDirectory($projectRoot.'/var/cache/test');

        if (!mkdir($testVarDirectory, 0777, true) && !is_dir($testVarDirectory)) {
            throw new RuntimeException('Unable to create var/test for the SQLite test database.');
        }

        self::runConsoleCommand($projectRoot, [
            'doctrine:migrations:migrate',
            '--no-interaction',
            '--env=test',
        ]);

        TestDatabaseSeeder::seed($testVarDirectory.'/test.db');
    }

    private static function acquireTestDatabaseLock(string $projectRoot): void
    {
        if (is_resource(self::$testDatabaseLock)) {
            return;
        }

        $varDirectory = $projectRoot.'/var';

        if (!is_dir($varDirectory) && !mkdir($varDirectory, 0777, true) && !is_dir($varDirectory)) {
            throw new RuntimeException('Unable to create var for the PHPUnit lifecycle lock.');
        }

        $lock = fopen($varDirectory.'/test-suite.lock', 'c');

        if (!is_resource($lock)) {
            throw new RuntimeException('Unable to create the PHPUnit lifecycle lock file.');
        }

        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            throw new RuntimeException('Another PHPUnit process is already using var/test. Run the test suite sequentially.');
        }

        self::$testDatabaseLock = $lock;
    }

    /**
     * @param list<string> $arguments
     */
    private static function runConsoleCommand(string $projectRoot, array $arguments): void
    {
        $command = [
            PHP_BINARY,
            $projectRoot.'/bin/console',
            ...$arguments,
        ];
        $environment = array_filter(array_merge($_SERVER, $_ENV, [
            'APP_ENV' => 'test',
            'APP_DEBUG' => '0',
            'DATABASE_URL' => 'sqlite:///%kernel.project_dir%/var/test/test.db',
            'SHELL_VERBOSITY' => '-1',
        ]), static fn (mixed $value): bool => is_scalar($value));

        $process = proc_open(
            $command,
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $projectRoot,
            $environment,
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start test database initialization command.');
        }

        $output = stream_get_contents($pipes[1]);
        $errorOutput = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if (0 !== $exitCode) {
            throw new RuntimeException(trim($output.PHP_EOL.$errorOutput));
        }
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
