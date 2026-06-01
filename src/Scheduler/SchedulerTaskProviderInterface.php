<?php

declare(strict_types=1);

namespace App\Scheduler;

interface SchedulerTaskProviderInterface
{
    /**
     * @return list<SchedulerTaskDefinition>
     */
    public function schedulerTasks(): array;
}
