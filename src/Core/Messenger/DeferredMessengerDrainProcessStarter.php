<?php

declare(strict_types=1);

namespace App\Core\Messenger;

use App\Core\Process\DetachedProcessStarter;

final readonly class DeferredMessengerDrainProcessStarter implements DeferredMessengerDrainStarterInterface
{
    public function __construct(private DetachedProcessStarter $starter)
    {
    }

    public function start(array $command, string $cwd, string $outputPath, string $pidPath): bool
    {
        return $this->starter->start($command, $cwd, $outputPath, $pidPath);
    }
}
