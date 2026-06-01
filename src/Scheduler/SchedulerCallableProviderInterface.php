<?php

declare(strict_types=1);

namespace App\Scheduler;

interface SchedulerCallableProviderInterface
{
    /**
     * @return callable(): SchedulerTaskExecution|null
     */
    public function schedulerCallable(string $target): ?callable;
}
