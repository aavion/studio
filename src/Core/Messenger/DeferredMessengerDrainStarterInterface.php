<?php

declare(strict_types=1);

namespace App\Core\Messenger;

interface DeferredMessengerDrainStarterInterface
{
    /**
     * @param list<string> $command
     */
    public function start(array $command, string $cwd, string $outputPath, string $pidPath): bool;
}
