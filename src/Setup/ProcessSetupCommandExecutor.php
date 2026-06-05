<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Process\CliProcessEnvironment;
use Symfony\Component\Process\Process;

final readonly class ProcessSetupCommandExecutor implements SetupCommandExecutorInterface
{
    /**
     * @param array<string, string|false> $environment
     */
    public function run(array $command, string $cwd, array $environment = []): SetupCommandResult
    {
        try {
            $process = new Process($command, $cwd, $this->processEnvironment($cwd, $environment), null, null);
            $process->run();
        } catch (\Throwable $error) {
            throw SetupStepFailedException::fromMessage(Message::exception(
                MessageCode::E_OPERATION_FAILED,
                MessageKey::OPERATION_FAILED,
                ['%operation%' => basename($command[0] ?? 'command')],
                ['command' => $command, 'cwd' => $cwd, 'exception' => $error::class, 'message' => $error->getMessage()],
            ));
        }

        return new SetupCommandResult($process->getExitCode() ?? 1, $process->getOutput(), $process->getErrorOutput());
    }

    /**
     * @param array<string, string|false> $environment
     *
     * @return array<string, string|false>
     */
    private function processEnvironment(string $cwd, array $environment): array
    {
        $processEnvironment = CliProcessEnvironment::fromCurrentProcess($environment);

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

        if ($this->hasNonEmptyEnvironmentValue($processEnvironment, 'COMPOSER_CACHE_DIR')) {
            $this->ensureDirectory($processEnvironment['COMPOSER_CACHE_DIR']);
        }

        return $processEnvironment;
    }

    /**
     * @param array<string, string|false> $environment
     */
    private function hasNonEmptyEnvironmentValue(array $environment, string $name): bool
    {
        return array_key_exists($name, $environment) && false !== $environment[$name] && '' !== trim($environment[$name]);
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
