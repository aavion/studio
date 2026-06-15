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
        if ('\\' !== DIRECTORY_SEPARATOR) {
            self::assertTrue(is_executable($this->scriptPath));
        }
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
        self::assertStringContainsString('$streamOutput ? STDOUT', $contents);
        self::assertStringContainsString('$streamOutput ? STDERR', $contents);
        self::assertStringContainsString('function nullDevice()', $contents);
        self::assertStringNotContainsString('stream_set_blocking', $contents);
        self::assertStringContainsString('resetVendorDirectory()', $contents);
        self::assertStringContainsString('Existing vendor directory removed before Composer install.', $contents);
        self::assertLessThan(
            strpos($contents, "'install', '--no-dev', '--no-scripts', '--optimize-autoloader'"),
            strpos($contents, 'resetVendorDirectory()'),
        );
        self::assertStringContainsString("'install', '--no-dev', '--no-scripts', '--optimize-autoloader'", $contents);
        self::assertStringContainsString('CoreTranslationBootstrapper', $contents);
        self::assertLessThan(
            strpos($contents, "in_array(\$environment, ['dev', 'test'], true)"),
            strpos($contents, 'generateCoreTranslations($environment)'),
        );
        self::assertStringContainsString("in_array(\$environment, ['dev', 'test'], true)", $contents);
        self::assertStringContainsString("'install', '--optimize-autoloader', '--no-scripts'", $contents);
        self::assertStringContainsString("'install', '--no-dev', '--optimize-autoloader', '--no-scripts'", $contents);
        self::assertStringContainsString("'cache:clear', '--no-warmup'", $contents);
        self::assertStringContainsString("'assets:install', 'public'", $contents);
        self::assertStringContainsString("'importmap:install'", $contents);
        self::assertStringContainsString("'ux:icons:lock'", $contents);
        self::assertStringContainsString('runOptionalCommand', $contents);
        self::assertStringContainsString("'tailwind:build'", $contents);
        self::assertStringContainsString("'cache:warmup'", $contents);
        self::assertStringContainsString("'asset-map:compile'", $contents);
        self::assertStringNotContainsString("'doctrine:migrations:migrate'", $contents);
        self::assertStringNotContainsString("'doctrine:schema:validate'", $contents);
        self::assertStringContainsString('bootEnv($this->projectDir.\'/.env\')', $contents);
    }
}
