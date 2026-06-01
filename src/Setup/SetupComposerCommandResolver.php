<?php

declare(strict_types=1);

namespace App\Setup;

final readonly class SetupComposerCommandResolver
{
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
        $bundledComposer = $projectDir.'/bin/composer';
        if (
            is_file($bundledComposer)
            && is_readable($bundledComposer)
            && $this->commandWorks([PHP_BINARY, $bundledComposer, '--version'], $projectDir, $commandExecutor, $environment)
        ) {
            return [PHP_BINARY, $bundledComposer];
        }

        if ($this->commandWorks(['composer', '--version'], $projectDir, $commandExecutor, $environment)) {
            return ['composer'];
        }

        throw new SetupStepFailedException('Composer is unavailable. Install Composer or restore bin/composer.');
    }

    /**
     * @return list<string>
     */
    public function plannedCommand(string $projectDir): array
    {
        $bundledComposer = $projectDir.'/bin/composer';

        return is_file($bundledComposer) && is_readable($bundledComposer) ? [PHP_BINARY, $bundledComposer] : ['composer'];
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
            return $commandExecutor->run($command, $projectDir, $environment)->isSuccessful();
        } catch (\Throwable) {
            return false;
        }
    }
}
