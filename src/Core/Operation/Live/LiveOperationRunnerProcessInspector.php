<?php

declare(strict_types=1);

namespace App\Core\Operation\Live;

use Symfony\Component\Process\Process;

final readonly class LiveOperationRunnerProcessInspector
{
    public function __construct(
        private LiveOperationRunStorage $storage,
    ) {
    }

    public function readRunnerPid(string $operationId): ?int
    {
        if (!$this->storage->validOperationId($operationId) || !is_file($this->storage->pidPath($operationId))) {
            return null;
        }

        $pid = trim((string) file_get_contents($this->storage->pidPath($operationId)));

        if (!ctype_digit($pid)) {
            return null;
        }

        $pidValue = (int) $pid;

        return $pidValue > 0 ? $pidValue : null;
    }

    public function runnerProcessMatches(string $operationId): bool
    {
        $pid = $this->readRunnerPid($operationId);

        if (null === $pid) {
            return false;
        }

        $command = $this->processCommand($pid);

        return null !== $command
            && str_contains($command, 'studio:operations:run')
            && str_contains($command, $operationId);
    }

    public function signalProcess(int $pid): bool
    {
        if ($this->isWindows()) {
            $process = new Process(['taskkill', '/PID', (string) $pid, '/T', '/F'], timeout: 2.0);
            $process->run();
            usleep(200000);

            return $process->isSuccessful() && null === $this->processCommand($pid);
        }

        if (function_exists('posix_kill')) {
            @posix_kill($pid, 15);
            usleep(200000);

            if (null === $this->processCommand($pid)) {
                return true;
            }

            @posix_kill($pid, 9);
            usleep(200000);

            return null === $this->processCommand($pid);
        }

        $process = new Process(['kill', '-TERM', (string) $pid], timeout: 2.0);
        $process->run();
        usleep(200000);

        return $process->isSuccessful() && null === $this->processCommand($pid);
    }

    private function processCommand(int $pid): ?string
    {
        if ($this->isWindows()) {
            return $this->windowsProcessCommand($pid);
        }

        $process = new Process(['ps', '-p', (string) $pid, '-o', 'command='], timeout: 2.0);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        $line = trim($process->getOutput());

        return '' !== $line ? $line : null;
    }

    private function windowsProcessCommand(int $pid): ?string
    {
        $filter = sprintf('ProcessId = %d', $pid);
        $powershell = new Process([
            'powershell',
            '-NoProfile',
            '-NonInteractive',
            '-Command',
            sprintf('(Get-CimInstance Win32_Process -Filter "%s").CommandLine', $filter),
        ], timeout: 2.0);
        $powershell->run();

        if ($powershell->isSuccessful()) {
            $line = trim($powershell->getOutput());

            if ('' !== $line) {
                return $line;
            }
        }

        $wmic = new Process(['wmic', 'process', 'where', 'processid='.(string) $pid, 'get', 'CommandLine', '/value'], timeout: 2.0);
        $wmic->run();

        if (!$wmic->isSuccessful()) {
            return null;
        }

        foreach (preg_split('/\R/', $wmic->getOutput()) ?: [] as $line) {
            $line = trim($line);

            if (str_starts_with($line, 'CommandLine=')) {
                $command = trim(substr($line, strlen('CommandLine=')));

                return '' !== $command ? $command : null;
            }
        }

        return null;
    }

    private function isWindows(): bool
    {
        return 'Windows' === PHP_OS_FAMILY;
    }
}
