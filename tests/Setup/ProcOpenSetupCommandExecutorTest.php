<?php

declare(strict_types=1);

namespace App\Tests\Setup;

use App\Setup\ProcOpenSetupCommandExecutor;
use PHPUnit\Framework\TestCase;

final class ProcOpenSetupCommandExecutorTest extends TestCase
{
    private string $root;

    /**
     * @var array<string, string|false>
     */
    private array $processEnvironmentBackup = [];

    /**
     * @var array<string, array{exists: bool, value: mixed}>
     */
    private array $serverBackup = [];

    /**
     * @var array<string, array{exists: bool, value: mixed}>
     */
    private array $envBackup = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/studio-setup-proc-open-test-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/var', 0777, true);
        $this->backupEnvironmentValue('COMPOSER_HOME');
    }

    protected function tearDown(): void
    {
        foreach ($this->processEnvironmentBackup as $name => $value) {
            false === $value ? putenv($name) : putenv($name.'='.$value);
        }

        foreach ($this->serverBackup as $name => $backup) {
            if ($backup['exists']) {
                $_SERVER[$name] = $backup['value'];
            } else {
                unset($_SERVER[$name]);
            }
        }

        foreach ($this->envBackup as $name => $backup) {
            if ($backup['exists']) {
                $_ENV[$name] = $backup['value'];
            } else {
                unset($_ENV[$name]);
            }
        }

        $this->removeDirectory($this->root);
    }

    public function testItProvidesLocalComposerHomeWhenComposerHomeIsMissing(): void
    {
        $this->unsetEnvironmentValue('COMPOSER_HOME');
        $executor = new ProcOpenSetupCommandExecutor();

        $result = $executor->run([PHP_BINARY, '-r', 'echo getenv("COMPOSER_HOME");'], $this->root);

        self::assertTrue($result->isSuccessful(), $result->errorOutput());
        self::assertSame($this->root.'/var/composer-home', $result->output());
        self::assertDirectoryExists($this->root.'/var/composer-home');
    }

    public function testItPreservesExplicitComposerHome(): void
    {
        $customComposerHome = $this->root.'/custom-composer-home';
        mkdir($customComposerHome);
        $executor = new ProcOpenSetupCommandExecutor();

        $result = $executor->run(
            [PHP_BINARY, '-r', 'echo getenv("COMPOSER_HOME");'],
            $this->root,
            ['COMPOSER_HOME' => $customComposerHome],
        );

        self::assertTrue($result->isSuccessful(), $result->errorOutput());
        self::assertSame($customComposerHome, $result->output());
    }

    private function backupEnvironmentValue(string $name): void
    {
        $this->processEnvironmentBackup[$name] = getenv($name);
        $this->serverBackup[$name] = [
            'exists' => array_key_exists($name, $_SERVER),
            'value' => $_SERVER[$name] ?? null,
        ];
        $this->envBackup[$name] = [
            'exists' => array_key_exists($name, $_ENV),
            'value' => $_ENV[$name] ?? null,
        ];
    }

    private function unsetEnvironmentValue(string $name): void
    {
        putenv($name);
        unset($_SERVER[$name], $_ENV[$name]);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($directory);
    }
}
