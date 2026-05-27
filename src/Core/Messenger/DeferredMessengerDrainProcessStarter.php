<?php

declare(strict_types=1);

namespace App\Core\Messenger;

use Symfony\Component\Process\Process;

final readonly class DeferredMessengerDrainProcessStarter implements DeferredMessengerDrainStarterInterface
{
    public function start(array $command, string $cwd, string $outputPath, string $pidPath): bool
    {
        $directory = dirname($outputPath);
        $pidDirectory = dirname($pidPath);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return false;
        }

        if (!is_dir($pidDirectory) && !mkdir($pidDirectory, 0775, true) && !is_dir($pidDirectory)) {
            return false;
        }

        $shellCommand = implode(' ', array_map('escapeshellarg', $command))
            .' > '.escapeshellarg($outputPath).' 2>&1 & echo $! > '.escapeshellarg($pidPath);

        $process = Process::fromShellCommandline($shellCommand, $cwd, timeout: 5.0);
        $process->run();

        return $process->isSuccessful();
    }
}
