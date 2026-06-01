<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Entity\SchedulerTask;

interface SchedulerTaskExecutorInterface
{
    public function supports(SchedulerTask $task): bool;

    public function execute(SchedulerTask $task): SchedulerTaskExecution;
}
