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
        if ($commandExecutor->run(['composer', '--version'], $projectDir, $environment)->isSuccessful()) {
            return ['composer'];
        }

        $bundledComposer = $projectDir.'/bin/composer';
        if (
            is_file($bundledComposer)
            && $commandExecutor->run([PHP_BINARY, $bundledComposer, '--version'], $projectDir, $environment)->isSuccessful()
        ) {
            return [PHP_BINARY, $bundledComposer];
        }

        throw new SetupStepFailedException('Composer is unavailable. Install Composer or restore bin/composer.');
    }

    /**
     * @return list<string>
     */
    public function plannedCommand(string $projectDir): array
    {
        $bundledComposer = $projectDir.'/bin/composer';

        return is_file($bundledComposer) ? [PHP_BINARY, $bundledComposer] : ['composer'];
    }
}
