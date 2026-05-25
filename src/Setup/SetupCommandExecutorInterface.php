<?php

declare(strict_types=1);

namespace App\Setup;

interface SetupCommandExecutorInterface
{
    /**
     * @param list<string> $command
     * @param array<string, string> $environment
     */
    public function run(array $command, string $cwd, array $environment = []): SetupCommandResult;
}
