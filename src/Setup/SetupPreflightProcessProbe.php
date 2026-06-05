<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\Process\CliProcessEnvironment;
use Symfony\Component\Process\Process;

final class SetupPreflightProcessProbe
{
    /**
     * @param list<string> $command
     * @param array<string, string>|null $environment
     */
    public function commandWorks(array $command, ?string $workingDirectory, ?array $environment = null, float $timeout = 5.0): bool
    {
        try {
            $process = new Process(
                $command,
                $workingDirectory,
                CliProcessEnvironment::fromCurrentProcess($environment ?? $this->pathEnvironment()),
                timeout: $timeout,
            );
            $process->run();
        } catch (\Throwable) {
            return false;
        }

        return $process->isSuccessful();
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $environment
     */
    public function composerCommandWorks(array $command, ?string $workingDirectory, array $environment): bool
    {
        try {
            $process = new Process($command, $workingDirectory, CliProcessEnvironment::fromCurrentProcess($environment), timeout: 5.0);
            $process->run();
        } catch (\Throwable) {
            return false;
        }

        return $process->isSuccessful()
            && str_contains($process->getOutput().$process->getErrorOutput(), 'Composer');
    }

    /**
     * @return array<string, string>
     */
    public function pathEnvironment(): array
    {
        $path = $_SERVER['PATH'] ?? $_ENV['PATH'] ?? getenv('PATH');

        return is_string($path) && '' !== $path ? ['PATH' => $path] : [];
    }
}
