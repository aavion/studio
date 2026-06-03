<?php

declare(strict_types=1);

namespace App\Tests\Setup;

use App\Setup\SetupCommandExecutorInterface;
use App\Setup\SetupCommandResult;
use App\Setup\SetupComposerCommandResolver;
use App\Setup\SetupStepFailedException;
use PHPUnit\Framework\TestCase;

final class SetupComposerCommandResolverTest extends TestCase
{
    public function testItPrefersBundledComposerWhenAvailable(): void
    {
        $root = sys_get_temp_dir().'/studio-composer-resolver-'.bin2hex(random_bytes(6));
        mkdir($root.'/bin', 0775, true);
        touch($root.'/bin/composer');
        chmod($root.'/bin/composer', 0755);

        $executor = new class implements SetupCommandExecutorInterface {
            /** @var list<list<string>> */
            public array $commands = [];

            public function run(array $command, string $cwd, array $environment = []): SetupCommandResult
            {
                $this->commands[] = $command;

                return new SetupCommandResult(0, 'Composer version test');
            }
        };

        try {
            $command = (new SetupComposerCommandResolver())->resolve($root, $executor, []);

            self::assertSame([PHP_BINARY, $root.'/bin/composer'], $command);
            self::assertSame([[PHP_BINARY, $root.'/bin/composer', '--version']], $executor->commands);
        } finally {
            @unlink($root.'/bin/composer');
            @rmdir($root.'/bin');
            @rmdir($root);
        }
    }

    public function testItAcceptsReadableBundledComposerWithoutExecutableBit(): void
    {
        $root = sys_get_temp_dir().'/studio-composer-resolver-'.bin2hex(random_bytes(6));
        mkdir($root.'/bin', 0775, true);
        touch($root.'/bin/composer');
        chmod($root.'/bin/composer', 0644);

        $executor = new class implements SetupCommandExecutorInterface {
            /** @var list<list<string>> */
            public array $commands = [];

            public function run(array $command, string $cwd, array $environment = []): SetupCommandResult
            {
                $this->commands[] = $command;

                return new SetupCommandResult(0, 'Composer version test');
            }
        };

        try {
            $command = (new SetupComposerCommandResolver())->resolve($root, $executor, []);

            self::assertSame([PHP_BINARY, $root.'/bin/composer'], $command);
            self::assertSame([[PHP_BINARY, $root.'/bin/composer', '--version']], $executor->commands);
        } finally {
            @unlink($root.'/bin/composer');
            @rmdir($root.'/bin');
            @rmdir($root);
        }
    }

    public function testItFallsBackToPathComposerWhenBundledComposerIsMissing(): void
    {
        $root = sys_get_temp_dir().'/studio-composer-resolver-'.bin2hex(random_bytes(6));
        mkdir($root, 0775, true);

        $executor = new class implements SetupCommandExecutorInterface {
            /** @var list<list<string>> */
            public array $commands = [];

            public function run(array $command, string $cwd, array $environment = []): SetupCommandResult
            {
                $this->commands[] = $command;

                return new SetupCommandResult(0, 'Composer version test');
            }
        };

        try {
            $command = (new SetupComposerCommandResolver())->resolve($root, $executor, []);

            self::assertSame(['composer'], $command);
            self::assertSame([['composer', '--version']], $executor->commands);
        } finally {
            @rmdir($root);
        }
    }

    public function testItUsesProjectLocalComposerEnvironment(): void
    {
        $root = sys_get_temp_dir().'/studio-composer-resolver-'.bin2hex(random_bytes(6));
        mkdir($root.'/bin', 0775, true);
        touch($root.'/bin/composer');

        $executor = new class implements SetupCommandExecutorInterface {
            /** @var list<array<string, string>> */
            public array $environments = [];

            public function run(array $command, string $cwd, array $environment = []): SetupCommandResult
            {
                $this->environments[] = $environment;

                return new SetupCommandResult(0, 'Composer version test');
            }
        };

        try {
            (new SetupComposerCommandResolver())->resolve($root, $executor, [
                'APP_ENV' => 'test',
                'COMPOSER_HOME' => '/bad/composer-home',
                'COMPOSER_CACHE_DIR' => '/bad/composer-cache',
                'HOME' => '/bad/home',
                'PATH' => '/usr/bin',
            ]);

            self::assertSame($root.'/var/composer-home', $executor->environments[0]['COMPOSER_HOME'] ?? null);
            self::assertSame($root.'/var/composer-cache', $executor->environments[0]['COMPOSER_CACHE_DIR'] ?? null);
            self::assertSame($root.'/var', $executor->environments[0]['HOME'] ?? null);
            self::assertSame('/usr/bin', $executor->environments[0]['PATH'] ?? null);
            self::assertSame('test', $executor->environments[0]['APP_ENV'] ?? null);
        } finally {
            @unlink($root.'/bin/composer');
            @rmdir($root.'/bin');
            @rmdir($root);
        }
    }

    public function testItFallsBackWhenBundledComposerCannotRun(): void
    {
        $root = sys_get_temp_dir().'/studio-composer-resolver-'.bin2hex(random_bytes(6));
        mkdir($root.'/bin', 0775, true);
        touch($root.'/bin/composer');
        chmod($root.'/bin/composer', 0755);

        $executor = new class implements SetupCommandExecutorInterface {
            /** @var list<list<string>> */
            public array $commands = [];

            public function run(array $command, string $cwd, array $environment = []): SetupCommandResult
            {
                $this->commands[] = $command;

                if (str_ends_with($command[1] ?? '', '/bin/composer')) {
                    throw new SetupStepFailedException('Bundled Composer failed.');
                }

                return new SetupCommandResult(0, 'Composer version test');
            }
        };

        try {
            $command = (new SetupComposerCommandResolver())->resolve($root, $executor, []);

            self::assertSame(['composer'], $command);
            self::assertSame([
                [PHP_BINARY, $root.'/bin/composer', '--version'],
                ['composer', '--version'],
            ], $executor->commands);
        } finally {
            @unlink($root.'/bin/composer');
            @rmdir($root.'/bin');
            @rmdir($root);
        }
    }

    public function testItRejectsSuccessfulNonComposerOutput(): void
    {
        $root = sys_get_temp_dir().'/studio-composer-resolver-'.bin2hex(random_bytes(6));
        mkdir($root.'/bin', 0775, true);
        touch($root.'/bin/composer');
        chmod($root.'/bin/composer', 0755);

        $executor = new class implements SetupCommandExecutorInterface {
            /** @var list<list<string>> */
            public array $commands = [];

            public function run(array $command, string $cwd, array $environment = []): SetupCommandResult
            {
                $this->commands[] = $command;

                return new SetupCommandResult(0, 'broken');
            }
        };

        try {
            $this->expectException(SetupStepFailedException::class);

            (new SetupComposerCommandResolver())->resolve($root, $executor, []);
        } finally {
            @unlink($root.'/bin/composer');
            @rmdir($root.'/bin');
            @rmdir($root);
        }
    }
}
