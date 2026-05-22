<?php

declare(strict_types=1);

namespace App\Tests\Operations;

use PHPUnit\Framework\TestCase;

final class SetupScriptTest extends TestCase
{
    private string $scriptPath;

    protected function setUp(): void
    {
        $this->scriptPath = dirname(__DIR__, 2).'/bin/setup';
    }

    public function testSetupScriptExistsAndIsExecutable(): void
    {
        self::assertFileExists($this->scriptPath);
        self::assertTrue(is_executable($this->scriptPath));
    }

    public function testSetupScriptHasValidPhpSyntax(): void
    {
        $output = [];
        $exitCode = 1;

        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($this->scriptPath), $output, $exitCode);

        self::assertSame(0, $exitCode, implode(PHP_EOL, $output));
    }

    public function testSetupScriptKeepsRealSetupStepsDeferred(): void
    {
        $contents = file_get_contents($this->scriptPath);

        self::assertIsString($contents);
        self::assertStringContainsString('initializeRepository', $contents);
        self::assertStringContainsString('collectInstallationData', $contents);
        self::assertStringContainsString('writeInitialConfiguration', $contents);
        self::assertStringContainsString('preparePersistentState', $contents);
        self::assertStringContainsString('intentionally deferred', $contents);
    }
}
