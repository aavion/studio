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

                return new SetupCommandResult(0);
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

                return new SetupCommandResult(0);
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

                return new SetupCommandResult(0);
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

                return new SetupCommandResult(0);
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
}
