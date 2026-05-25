<?php

declare(strict_types=1);

namespace App\Setup;

use RuntimeException;

final readonly class ProcOpenSetupCommandExecutor implements SetupCommandExecutorInterface
{
    public function run(array $command, string $cwd, array $environment = []): SetupCommandResult
    {
        $process = proc_open(
            $command,
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $cwd,
            array_filter([...$_SERVER, ...$_ENV, ...$environment], static fn (mixed $value): bool => is_scalar($value)),
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start setup command.');
        }

        $output = stream_get_contents($pipes[1]);
        $errorOutput = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        return new SetupCommandResult(proc_close($process), (string) $output, (string) $errorOutput);
    }
}
