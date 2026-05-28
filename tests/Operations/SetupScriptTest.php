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

    public function testSetupScriptDelegatesToSetupRunner(): void
    {
        $contents = file_get_contents($this->scriptPath);

        self::assertIsString($contents);
        self::assertStringContainsString('Dotenv', $contents);
        self::assertStringContainsString('bootEnv', $contents);
        self::assertStringContainsString('SetupRunner', $contents);
        self::assertStringNotContainsString('CoreTranslationBootstrapper', $contents);
        self::assertStringContainsString('SetupCliInputFactory', $contents);
        self::assertStringContainsString('dry-run', $contents);
        self::assertStringContainsString('reset-password', $contents);
        self::assertStringContainsString('no-interaction', $contents);
        self::assertStringContainsString('json_encode', $contents);
        self::assertStringContainsString('workflowResultMessageReporter($projectDir, $environment)', $contents);
    }

    public function testSetupScriptLocalizesHumanOutput(): void
    {
        $output = [];
        $exitCode = 1;
        $projectDir = dirname(__DIR__, 2);
        $testLogPattern = $projectDir.'/var/log/test.studio-message-*.log';
        $devLogPattern = $projectDir.'/var/log/dev.studio-message-*.log';
        $devLogFiles = glob($devLogPattern) ?: [];

        foreach (glob($testLogPattern) ?: [] as $testLog) {
            @unlink($testLog);
        }

        $command = implode(' ', [
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->scriptPath),
            '--dry-run',
            '--no-interaction',
            '--env=test',
            '--language=de',
            '--site-title='.escapeshellarg('Dry Studio'),
            '--url=https://dry.example.test',
            '--db-driver=sqlite',
            '--admin-password='.escapeshellarg('admin-password'),
        ]);

        exec($command, $output, $exitCode);
        $text = implode(PHP_EOL, $output);

        self::assertSame(0, $exitCode, $text);
        self::assertStringContainsString('Setup erfolgreich abgeschlossen.', $text);
        self::assertStringContainsString('Installer-Sprache "de" ausgewählt.', $text);
        self::assertStringContainsString('Verfügbare Installer-Sprachen: de, en', $text);
        self::assertStringNotContainsString('message.setup.language_selected', $text);
        self::assertNotSame([], glob($testLogPattern) ?: []);
        self::assertSame($devLogFiles, glob($devLogPattern) ?: []);
    }
}
