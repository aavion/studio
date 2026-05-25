<?php

declare(strict_types=1);

namespace App\Tests\Operations;

use PHPUnit\Framework\TestCase;

final class InitScriptTest extends TestCase
{
    private string $scriptPath;

    protected function setUp(): void
    {
        $this->scriptPath = dirname(__DIR__, 2).'/bin/init';
    }

    public function testInitScriptExistsAndIsExecutable(): void
    {
        self::assertFileExists($this->scriptPath);
        self::assertTrue(is_executable($this->scriptPath));
    }

    public function testInitScriptHasValidPhpSyntax(): void
    {
        $output = [];
        $exitCode = 1;

        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($this->scriptPath), $output, $exitCode);

        self::assertSame(0, $exitCode, implode(PHP_EOL, $output));
    }

    public function testInitScriptCoversRequiredInitializationSteps(): void
    {
        $contents = file_get_contents($this->scriptPath);

        self::assertIsString($contents);
        self::assertStringContainsString("'composer', '--version'", $contents);
        self::assertStringContainsString("/bin/composer'", $contents);
        self::assertStringContainsString("'install', '--no-dev', '--no-scripts', '--optimize-autoloader'", $contents);
        self::assertStringContainsString('CoreTranslationBootstrapper', $contents);
        self::assertLessThan(
            strpos($contents, "in_array(\$environment, ['dev', 'test'], true)"),
            strpos($contents, 'generateCoreTranslations()'),
        );
        self::assertStringContainsString("in_array(\$environment, ['dev', 'test'], true)", $contents);
        self::assertStringContainsString("'install', '--optimize-autoloader'", $contents);
        self::assertStringContainsString("'install', '--no-dev', '--optimize-autoloader'", $contents);
        self::assertStringContainsString("'asset-map:compile'", $contents);
        self::assertStringNotContainsString("'doctrine:migrations:migrate'", $contents);
        self::assertStringNotContainsString("'doctrine:schema:validate'", $contents);
        self::assertStringNotContainsString("'importmap:install'", $contents);
        self::assertStringNotContainsString("'tailwind:build'", $contents);
        self::assertStringContainsString('bootEnv($this->projectDir.\'/.env\')', $contents);
    }
}
