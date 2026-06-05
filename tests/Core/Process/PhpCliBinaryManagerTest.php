<?php

declare(strict_types=1);

namespace App\Tests\Core\Process;

use App\Core\Process\PhpCliBinaryManager;
use App\Core\Process\PhpCliBinaryPreferenceStore;
use App\Core\Process\PhpCliBinaryValidator;
use App\Tests\Support\FilesystemTestHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class PhpCliBinaryManagerTest extends TestCase
{
    use FilesystemTestHelper;

    private string $root;

    protected function setUp(): void
    {
        $this->root = $this->createTemporaryDirectory('php-cli-manager');
        mkdir($this->root.'/bin', 0775, true);
        file_put_contents($this->root.'/bin/console', "#!/usr/bin/env php\n<?php echo \"Studio test\";\n");
        file_put_contents($this->root.'/composer.json', json_encode([
            'require' => [
                'php' => '>=8.4.1',
                'ext-json' => '*',
            ],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testItUsesStoredValidPhpBinaryFirst(): void
    {
        file_put_contents($this->root.'/.env.test.local', "APP_DEFAULT_PHP_BINARY='".str_replace(['\\', "'"], ['\\\\', "\\'"], PHP_BINARY)."'\n");

        $resolution = (new PhpCliBinaryManager())->resolve($this->root, 'test');

        self::assertTrue($resolution->isAvailable(), $resolution->reason());
        self::assertSame([PHP_BINARY], $resolution->commandPrefix());
        self::assertSame('preferred', $resolution->context()['source'] ?? null);
    }

    public function testItAcceptsStoredPathBasedPhpBinaryWhenItValidates(): void
    {
        file_put_contents($this->root.'/.env.test.local', "APP_DEFAULT_PHP_BINARY='php'\n");

        $resolution = (new PhpCliBinaryManager())->resolve($this->root, 'test');

        self::assertTrue($resolution->isAvailable(), $resolution->reason());
        self::assertSame(['php'], $resolution->commandPrefix());
        self::assertSame('preferred', $resolution->context()['source'] ?? null);
    }

    public function testItRefreshesBrokenStoredPhpBinaryWhenPersistenceIsAllowed(): void
    {
        file_put_contents($this->root.'/.env.test.local', "APP_DEFAULT_PHP_BINARY='/missing/php'\n");

        $resolution = (new PhpCliBinaryManager())->resolve($this->root, 'test', persistPreference: true);

        self::assertTrue($resolution->isAvailable(), $resolution->reason());
        self::assertSame([PHP_BINARY], $resolution->commandPrefix());
        self::assertSame('resolved_after_preference_failed', $resolution->context()['source'] ?? null);
        self::assertSame(PHP_BINARY, (new PhpCliBinaryPreferenceStore())->read($this->root, 'test'));
        self::assertTrue($resolution->context()['preference_write']['persisted'] ?? false);
    }

    public function testValidatorRejectsMissingProjectRequirements(): void
    {
        file_put_contents($this->root.'/composer.json', json_encode([
            'require' => [
                'php' => '>=8.4.1',
                'ext-definitely_missing_for_studio_tests' => '*',
            ],
        ], JSON_THROW_ON_ERROR));

        $result = (new PhpCliBinaryValidator())->validate([PHP_BINARY], $this->root);

        self::assertFalse($result->isValid());
        self::assertSame('extension_missing', $result->reason());
    }

    public function testResolvedBinaryRunsAsCliAndCanReadProjectConsole(): void
    {
        $resolution = (new PhpCliBinaryManager())->resolve($this->root, 'test');

        self::assertTrue($resolution->isAvailable(), $resolution->reason());

        $process = new Process([...$resolution->commandPrefix(), '-r', 'echo is_readable($argv[1]) ? PHP_SAPI : "no";', $this->root.'/bin/console'], $this->root);
        $process->run();

        self::assertTrue($process->isSuccessful());
        self::assertSame('cli', trim($process->getOutput()));
    }
}
