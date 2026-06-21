<?php

declare(strict_types=1);

namespace App\Core\Process;

use Symfony\Component\Process\Process;

final readonly class DetachedProcessStarter
{
    /**
     * @param list<string> $command
     * @param array<string, string|false> $environment
     *
     * Keep descriptor cleanup opt-in for long-lived services that must outlive
     * the current process, such as Mercure. Operation and scheduler runners
     * intentionally inherit locks by default to prevent unsafe parallel runs.
     */
    public function start(
        array $command,
        string $cwd,
        string $outputPath,
        string $pidPath,
        array $environment = [],
        bool $closeInheritedFileDescriptors = false,
    ): bool {
        $outputDirectory = dirname($outputPath);
        $pidDirectory = dirname($pidPath);

        if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)) {
            return false;
        }

        if (!is_dir($pidDirectory) && !mkdir($pidDirectory, 0775, true) && !is_dir($pidDirectory)) {
            return false;
        }

        if ('\\' === DIRECTORY_SEPARATOR) {
            return $this->startWindows($command, $cwd, $outputPath, $pidPath, $environment);
        }

        $detachedCommand = $closeInheritedFileDescriptors
            ? 'sh -c '.escapeshellarg($this->closeInheritedDescriptorsCommand().'exec "$@"').' '.escapeshellarg('detached-process').' '.implode(' ', array_map('escapeshellarg', $command))
            : implode(' ', array_map('escapeshellarg', $command));

        $shellCommand = 'nohup '.$detachedCommand
            .' > '.escapeshellarg($outputPath).' 2>&1 < /dev/null & echo $! > '.escapeshellarg($pidPath);

        return $this->runShellCommand($shellCommand, $cwd, $environment);
    }

    /**
     * @param list<string> $command
     * @param array<string, string|false> $environment
     */
    private function startWindows(array $command, string $cwd, string $outputPath, string $pidPath, array $environment): bool
    {
        if (false === file_put_contents($pidPath, 'started '.gmdate('c').PHP_EOL, LOCK_EX)) {
            return false;
        }

        $shellCommand = 'start "" /B '.implode(' ', array_map($this->windowsArgument(...), $command))
            .' > '.$this->windowsArgument($outputPath).' 2>&1';

        return $this->runShellCommand('cmd /C '.$shellCommand, $cwd, $environment);
    }

    /**
     * @param array<string, string|false> $environment
     */
    private function runShellCommand(string $shellCommand, string $cwd, array $environment): bool
    {
        // Symfony Process stops async children on destruction, so the shell
        // detaches the actual runner while this Process only starts the shell.
        $process = Process::fromShellCommandline(
            $shellCommand,
            $cwd,
            CliProcessEnvironment::fromCurrentProcess($environment),
            timeout: 5.0,
        );
        $process->run();

        return $process->isSuccessful();
    }

    private function windowsArgument(string $argument): string
    {
        return '"'.str_replace('"', '\"', $argument).'"';
    }

    private function closeInheritedDescriptorsCommand(): string
    {
        return 'if [ -d /proc/self/fd ]; then for fd in /proc/self/fd/*; do fd=${fd##*/}; case "$fd" in ""|0|1|2|*[!0-9]*) continue;; esac; eval "exec $fd>&-"; done; else i=3; limit=$(getconf OPEN_MAX 2>/dev/null || echo 1024); while [ "$i" -lt "$limit" ]; do eval "exec $i>&-"; i=$((i + 1)); done; fi; ';
    }
}
