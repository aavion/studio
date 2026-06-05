<?php

declare(strict_types=1);

namespace App\Tests\Core\Process;

use App\Core\Process\CliProcessEnvironment;
use PHPUnit\Framework\TestCase;

final class CliProcessEnvironmentTest extends TestCase
{
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
    }

    public function testItRemovesInheritedWebContextVariables(): void
    {
        $this->backupEnvironmentValue('HTTP_HOST');
        $this->backupEnvironmentValue('REQUEST_URI');
        putenv('HTTP_HOST=example.test');
        $_SERVER['REQUEST_URI'] = '/setup';

        $environment = CliProcessEnvironment::withoutWebContext(['APP_ENV' => 'test']);

        self::assertFalse($environment['HTTP_HOST'] ?? null);
        self::assertFalse($environment['REQUEST_URI'] ?? null);
        self::assertSame('test', $environment['APP_ENV'] ?? null);
    }

    public function testExplicitEnvironmentCanOverrideWebContextRemoval(): void
    {
        $this->backupEnvironmentValue('HTTP_HOST');
        putenv('HTTP_HOST=example.test');

        $environment = CliProcessEnvironment::withoutWebContext(['HTTP_HOST' => 'explicit.test']);

        self::assertSame('explicit.test', $environment['HTTP_HOST'] ?? null);
    }

    public function testItRemovesWebContextAfterInheritedEnvironmentWasCollected(): void
    {
        $environment = CliProcessEnvironment::removeWebContextFrom([
            'HTTP_HOST' => 'example.test',
            'APP_ENV' => 'test',
        ]);

        self::assertFalse($environment['HTTP_HOST'] ?? null);
        self::assertSame('test', $environment['APP_ENV'] ?? null);
    }

    public function testItRemovesStaleWebIdentityVariablesOnlyInWebContext(): void
    {
        $this->backupEnvironmentValue('USER');
        $this->backupEnvironmentValue('HOME');
        $this->backupEnvironmentValue('REQUEST_METHOD');
        putenv('USER=root');
        putenv('HOME=/root');
        putenv('REQUEST_METHOD=GET');

        $environment = CliProcessEnvironment::withoutWebContext();

        self::assertFalse($environment['USER'] ?? null);
        self::assertFalse($environment['HOME'] ?? null);
    }

    public function testItKeepsCliIdentityVariablesOutsideWebContext(): void
    {
        $this->backupEnvironmentValue('USER');
        $this->backupEnvironmentValue('REQUEST_METHOD');
        $this->backupEnvironmentValue('GATEWAY_INTERFACE');
        $this->backupEnvironmentValue('FCGI_ROLE');
        $this->backupEnvironmentValue('DOCUMENT_ROOT');
        $this->backupEnvironmentValue('HTTP_HOST');
        putenv('REQUEST_METHOD');
        putenv('GATEWAY_INTERFACE');
        putenv('FCGI_ROLE');
        putenv('DOCUMENT_ROOT');
        putenv('HTTP_HOST');
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['GATEWAY_INTERFACE'], $_SERVER['FCGI_ROLE'], $_SERVER['DOCUMENT_ROOT'], $_SERVER['HTTP_HOST']);
        putenv('USER=developer');

        $environment = CliProcessEnvironment::withoutWebContext();

        self::assertArrayNotHasKey('USER', $environment);
    }

    public function testItPassesDotenvValuesFromCurrentProcess(): void
    {
        $this->backupEnvironmentValue('APP_SECRET');
        $_SERVER['APP_SECRET'] = 'dotenv-secret';
        $_ENV['APP_SECRET'] = 'dotenv-secret';

        $environment = CliProcessEnvironment::fromCurrentProcess();

        self::assertSame('dotenv-secret', $environment['APP_SECRET'] ?? null);
    }

    public function testItKeepsExplicitHomeOverrideWhileRemovingInheritedWebIdentity(): void
    {
        $this->backupEnvironmentValue('HOME');
        $this->backupEnvironmentValue('REQUEST_METHOD');
        putenv('HOME=/root');
        putenv('REQUEST_METHOD=GET');

        $environment = CliProcessEnvironment::fromCurrentProcess(['HOME' => '/project/var']);

        self::assertSame('/project/var', $environment['HOME'] ?? null);
    }

    public function testItDoesNotPassExplicitHttpRequestValuesToChildProcesses(): void
    {
        $this->backupEnvironmentValue('HTTP_HOST');

        $environment = CliProcessEnvironment::fromCurrentProcess(['HTTP_HOST' => 'explicit.test']);

        self::assertFalse($environment['HTTP_HOST'] ?? null);
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
}
