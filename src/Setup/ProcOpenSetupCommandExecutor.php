<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use RuntimeException;

final readonly class ProcOpenSetupCommandExecutor implements SetupCommandExecutorInterface
{
    public function run(array $command, string $cwd, array $environment = []): SetupCommandResult
    {
        $processEnvironment = $this->processEnvironment($cwd, $environment);
        $process = proc_open(
            $command,
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $cwd,
            $processEnvironment,
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

    /**
     * @param array<string, string> $environment
     *
     * @return array<string, string>
     */
    private function processEnvironment(string $cwd, array $environment): array
    {
        $processEnvironment = [
            ...$this->scalarEnvironment(getenv()),
            ...$this->scalarEnvironment($_SERVER),
            ...$this->scalarEnvironment($_ENV),
            ...$environment,
        ];

        if (!$this->hasNonEmptyEnvironmentValue($processEnvironment, 'COMPOSER_HOME')) {
            $composerHome = $cwd.'/var/composer-home';
            $this->ensureDirectory($composerHome);
            $processEnvironment['COMPOSER_HOME'] = $composerHome;
        }

        if (!$this->hasNonEmptyEnvironmentValue($processEnvironment, 'HOME')) {
            $home = $cwd.'/var';
            $this->ensureDirectory($home);
            $processEnvironment['HOME'] = $home;
        }

        return $processEnvironment;
    }

    /**
     * @param array<mixed>|false $environment
     *
     * @return array<string, string>
     */
    private function scalarEnvironment(array|false $environment): array
    {
        if (false === $environment) {
            return [];
        }

        $scalars = [];
        foreach ($environment as $name => $value) {
            if (!is_string($name) || '' === trim($name) || !is_scalar($value)) {
                continue;
            }

            $scalars[$name] = (string) $value;
        }

        return $scalars;
    }

    /**
     * @param array<string, string> $environment
     */
    private function hasNonEmptyEnvironmentValue(array $environment, string $name): bool
    {
        return array_key_exists($name, $environment) && '' !== trim($environment[$name]);
    }

    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0775, true) && !is_dir($path)) {
            throw SetupStepFailedException::fromMessage(Message::error(
                MessageCode::FILESYSTEM_DIRECTORY_CREATE_FAILED,
                MessageKey::FILESYSTEM_DIRECTORY_CREATE_FAILED,
                ['%path%' => basename($path)],
                ['path' => $path],
            ));
        }
    }
}
