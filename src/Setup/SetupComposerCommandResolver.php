<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\Process\PhpCliBinaryManager;

final readonly class SetupComposerCommandResolver
{
    public function __construct(
        private PhpCliBinaryManager $phpCliBinaryManager = new PhpCliBinaryManager(),
        private SetupComposerEnvironment $composerEnvironment = new SetupComposerEnvironment(),
    ) {
    }

    /**
     * @param array<string, string> $environment
     *
     * @return list<string>
     */
    public function resolve(
        string $projectDir,
        SetupCommandExecutorInterface $commandExecutor,
        array $environment,
    ): array {
        $environment = $this->environment($projectDir, $environment);
        $bundledComposer = $projectDir.'/bin/composer';
        $phpCli = $this->phpCliBinaryManager->resolve($projectDir, $this->appEnv($environment), $environment);
        $phpCommand = $phpCli->commandPrefix();

        if (
            $phpCli->isAvailable()
            && [] !== $phpCommand
            && is_file($bundledComposer)
            && is_readable($bundledComposer)
            && $this->commandWorks([...$phpCommand, $bundledComposer, '--version'], $projectDir, $commandExecutor, $environment)
        ) {
            return [...$phpCommand, $bundledComposer];
        }

        if ($this->commandWorks(['composer', '--version'], $projectDir, $commandExecutor, $environment)) {
            return ['composer'];
        }

        throw new SetupStepFailedException('Composer is unavailable. Install Composer or restore bin/composer.');
    }

    /**
     * @param array<string, string> $environment
     *
     * @return array<string, string>
     */
    public function environment(string $projectDir, array $environment = []): array
    {
        return $this->composerEnvironment->create($projectDir, $environment);
    }

    /**
     * @return list<string>
     */
    public function plannedCommand(string $projectDir): array
    {
        $bundledComposer = $projectDir.'/bin/composer';
        $phpCli = $this->phpCliBinaryManager->resolve($projectDir, $this->appEnv());
        $phpCommand = $phpCli->commandPrefix();

        return $phpCli->isAvailable() && is_file($bundledComposer) && is_readable($bundledComposer)
            ? [...$phpCommand, $bundledComposer]
            : ['composer'];
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $environment
     */
    private function commandWorks(
        array $command,
        string $projectDir,
        SetupCommandExecutorInterface $commandExecutor,
        array $environment,
    ): bool {
        try {
            $result = $commandExecutor->run($command, $projectDir, $environment);

            return $result->isSuccessful()
                && str_contains($result->output().$result->errorOutput(), 'Composer');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, string> $environment
     */
    private function appEnv(array $environment = []): string
    {
        $appEnv = $environment['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV');

        return is_string($appEnv) && '' !== trim($appEnv) ? trim($appEnv) : 'dev';
    }
}
