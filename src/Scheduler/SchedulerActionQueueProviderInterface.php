<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Core\Operation\ActionQueue;

interface SchedulerActionQueueProviderInterface
{
    public function schedulerActionQueue(string $target): ?ActionQueue;
}
