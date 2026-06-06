<?php

declare(strict_types=1);

namespace App\Tests\Operations;

use PHPUnit\Framework\TestCase;

final class SchedulerScriptTest extends TestCase
{
    private string $scriptPath;

    protected function setUp(): void
    {
        $this->scriptPath = dirname(__DIR__, 2).'/bin/scheduler';
    }

    public function testSchedulerScriptExistsAndIsExecutable(): void
    {
        self::assertFileExists($this->scriptPath);
        if ('\\' !== DIRECTORY_SEPARATOR) {
            self::assertTrue(is_executable($this->scriptPath));
        }
    }

    public function testSchedulerScriptHasValidPhpSyntax(): void
    {
        $output = [];
        $exitCode = 1;

        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($this->scriptPath), $output, $exitCode);

        self::assertSame(0, $exitCode, implode(PHP_EOL, $output));
    }

    public function testSchedulerScriptFiltersWebEnvironmentFromConsoleChild(): void
    {
        $contents = file_get_contents($this->scriptPath);

        self::assertIsString($contents);
        self::assertStringContainsString('CliProcessEnvironment', $contents);
        self::assertStringContainsString('CliProcessEnvironment::fromCurrentProcess()', $contents);
        self::assertStringContainsString('scheduler:run', $contents);
    }
}
