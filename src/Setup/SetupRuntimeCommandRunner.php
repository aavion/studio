<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\Message\Message;
use App\Core\Process\PhpCliBinaryManager;
use App\Database\DatabaseReadyState;

final readonly class SetupRuntimeCommandRunner
{
    public function __construct(
        private SetupComposerCommandResolver $composerCommandResolver = new SetupComposerCommandResolver(),
        private PhpCliBinaryManager $phpCliBinaryManager = new PhpCliBinaryManager(),
        private SetupDatabaseEnvironmentScope $databaseEnvironmentScope = new SetupDatabaseEnvironmentScope(),
        private SetupOperationPayloadMessageExtractor $payloadMessageExtractor = new SetupOperationPayloadMessageExtractor(),
    ) {
    }

    /**
     * @param array<string, string> $environment
     *
     * @return array<string, mixed>
     */
    public function dumpEnvironment(
        string $projectDir,
        SetupInput $input,
        array $environment,
        SetupCommandExecutorInterface $commandExecutor,
    ): array {
        $composerEnvironment = $this->composerCommandResolver->environment($projectDir, $environment);
        $composer = $this->composerCommandResolver->resolve($projectDir, $commandExecutor, $composerEnvironment);
        $command = [...$composer, 'dump-env', $input->appEnv()];
        $result = $commandExecutor->run($command, $projectDir, $composerEnvironment);

        if (!$result->isSuccessful()) {
            throw $this->commandFailed($result);
        }

        return ['command' => $command];
    }

    /**
     * @param array<string, string> $environment
     *
     * @return array<string, mixed>
     */
    public function runMigrations(
        string $projectDir,
        SetupInput $input,
        array $environment,
        SetupCommandExecutorInterface $commandExecutor,
    ): array {
        $command = $this->migrationCommand($projectDir, $input);
        $result = $commandExecutor->run($command, $projectDir, $this->databaseEnvironmentScope->commandEnvironment($environment));

        if (!$result->isSuccessful()) {
            throw $this->commandFailed($result);
        }

        return ['command' => $command];
    }

    /**
     * @param array<string, string> $environment
     *
     * @return array<string, mixed>
     */
    public function clearCache(
        string $projectDir,
        SetupInput $input,
        array $environment,
        SetupCommandExecutorInterface $commandExecutor,
    ): array {
        $command = $this->cacheClearCommand($projectDir, $input);
        $result = $commandExecutor->run($command, $projectDir, $this->databaseEnvironmentScope->commandEnvironment($environment));

        if (!$result->isSuccessful()) {
            throw $this->commandFailed($result);
        }

        return ['command' => $command];
    }

    /**
     * @param array<string, string> $environment
     *
     * @return array<string, mixed>
     */
    public function runPackageDiscovery(
        string $projectDir,
        SetupInput $input,
        array $environment,
        SetupCommandExecutorInterface $commandExecutor,
    ): array {
        $command = [
            ...$this->phpCliCommandPrefix($projectDir, $input, $environment, true),
            $projectDir.'/bin/console',
            'packages:discover',
            '--run-now',
            '--trigger=setup',
            '--env='.$input->appEnv(),
        ];
        $result = $commandExecutor->run($command, $projectDir, $this->databaseEnvironmentScope->commandEnvironment($environment));

        if (!$result->isSuccessful()) {
            throw $this->commandFailed($result);
        }

        return ['command' => $command];
    }

    /**
     * @param array<string, string> $environment
     *
     * @return array<string, mixed>
     */
    public function runAssetRebuild(
        string $projectDir,
        SetupInput $input,
        array $environment,
        SetupCommandExecutorInterface $commandExecutor,
    ): array {
        $phpResolutionEnvironment = $this->phpResolutionEnvironment($environment);
        $commandEnvironment = $this->assetRebuildCommandEnvironment($input, $phpResolutionEnvironment);
        $command = [
            ...$this->phpCliCommandPrefix($projectDir, $input, $phpResolutionEnvironment, true),
            $projectDir.'/bin/console',
            'assets:rebuild',
            '--trigger=setup',
            '--env='.$input->appEnv(),
            '--json',
        ];
        $result = $commandExecutor->run($command, $projectDir, $commandEnvironment);

        if (!$result->isSuccessful()) {
            throw $this->commandFailed($result);
        }

        return [
            'command' => $command,
            ...$this->payloadMessageExtractor->contextFromOutput($result->output()),
        ];
    }

    /**
     * @param array<string, string> $environment
     *
     * @return array<string, mixed>
     */
    public function runMercureHealth(
        string $projectDir,
        SetupInput $input,
        array $environment,
        SetupCommandExecutorInterface $commandExecutor,
    ): array {
        $command = [
            ...$this->phpCliCommandPrefix($projectDir, $input, $environment, true),
            $projectDir.'/bin/console',
            'mercure:health',
            '--env='.$input->appEnv(),
        ];
        $result = $commandExecutor->run($command, $projectDir, $this->databaseEnvironmentScope->commandEnvironment($environment));

        return [
            'command' => $command,
            'available' => $result->isSuccessful(),
        ];
    }

    /**
     * @return list<string>
     */
    public function dryRunMigrationCommand(string $projectDir, SetupInput $input): array
    {
        $resolution = $this->phpCliBinaryManager->resolve($projectDir, $input->appEnv());

        return [
            ...($resolution->isAvailable() ? $resolution->commandPrefix() : ['php-cli-unavailable:'.$resolution->reason()]),
            $projectDir.'/bin/console',
            'doctrine:migrations:migrate',
            '--no-interaction',
            '--env='.$input->appEnv(),
        ];
    }

    /**
     * @param array<string, string> $environment
     *
     * @return array<string, mixed>
     */
    public function resolvePhpCli(string $projectDir, SetupInput $input, array $environment): array
    {
        $resolution = $this->phpCliBinaryManager->resolve(
            $projectDir,
            $input->appEnv(),
            $this->phpResolutionEnvironment($environment),
            true,
        );

        if (!$resolution->isAvailable()) {
            throw $this->phpCliUnavailable($resolution->reason());
        }

        return [
            'command_prefix' => $resolution->commandPrefix(),
            ...$resolution->context(),
        ];
    }

    /**
     * @return list<string>
     */
    private function migrationCommand(string $projectDir, SetupInput $input): array
    {
        return [
            ...$this->phpCliCommandPrefix($projectDir, $input),
            $projectDir.'/bin/console',
            'doctrine:migrations:migrate',
            '--no-interaction',
            '--env='.$input->appEnv(),
        ];
    }

    /**
     * @return list<string>
     */
    private function cacheClearCommand(string $projectDir, SetupInput $input): array
    {
        return [
            ...$this->phpCliCommandPrefix($projectDir, $input),
            $projectDir.'/bin/console',
            'cache:clear',
            '--env='.$input->appEnv(),
        ];
    }

    /**
     * @param array<string, string> $environment
     *
     * @return list<string>
     */
    private function phpCliCommandPrefix(
        string $projectDir,
        SetupInput $input,
        array $environment = [],
        bool $persistPreference = false,
    ): array {
        $resolution = $this->phpCliBinaryManager->resolve($projectDir, $input->appEnv(), $environment, $persistPreference);

        if (!$resolution->isAvailable()) {
            throw $this->phpCliUnavailable($resolution->reason());
        }

        return $resolution->commandPrefix();
    }

    /**
     * @param array<string, string> $environment
     *
     * @return array<string, string|false>
     */
    private function assetRebuildCommandEnvironment(SetupInput $input, array $environment): array
    {
        return [
            ...$environment,
            'APP_ENV' => $input->appEnv(),
            'APP_DEBUG' => false,
            'APP_SECRET' => false,
            'DATABASE_URL' => false,
            'APP_DATABASE_PREFIX' => false,
            'DEFAULT_URI' => false,
            'SHELL_VERBOSITY' => '0',
            DatabaseReadyState::ALLOW_UNREADY_KEY => '1',
        ];
    }

    /**
     * @param array<string, string> $environment
     *
     * @return array<string, string>
     */
    private function phpResolutionEnvironment(array $environment): array
    {
        $phpResolutionEnvironment = [];
        foreach (['PATH', 'SystemRoot', 'WINDIR'] as $name) {
            if (array_key_exists($name, $environment)) {
                $phpResolutionEnvironment[$name] = $environment[$name];
            }
        }

        return $phpResolutionEnvironment;
    }

    private function commandError(SetupCommandResult $result): string
    {
        return trim($result->output().PHP_EOL.$result->errorOutput()) ?: 'Setup command failed.';
    }

    private function commandFailed(SetupCommandResult $result): SetupStepFailedException
    {
        return SetupStepFailedException::fromMessage(Message::error(
            SetupMessageCode::SETUP_RUNTIME_COMMAND_FAILED,
            SetupMessageKey::SETUP_RUNTIME_COMMAND_FAILED,
            ['%message%' => $this->commandError($result)],
        ));
    }

    private function phpCliUnavailable(string $reason): SetupStepFailedException
    {
        return SetupStepFailedException::fromMessage(Message::error(
            SetupMessageCode::SETUP_PHP_CLI_UNAVAILABLE,
            SetupMessageKey::SETUP_PHP_CLI_UNAVAILABLE,
            ['%reason%' => $reason],
        ));
    }
}
