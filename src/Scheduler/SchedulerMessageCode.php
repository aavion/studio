<?php

declare(strict_types=1);

namespace App\Scheduler;

final class SchedulerMessageCode
{
    public const SCHEDULER_RUN_COMPLETED = 'scheduler.run_completed';
    public const SCHEDULER_RUN_FAILED = 'scheduler.run_failed';
    public const SCHEDULER_TASK_FAILED = 'scheduler.task_failed';
    public const SCHEDULER_TASK_DISABLED = 'scheduler.task_disabled';
    public const SCHEDULER_TASK_INVALID_CRON_DISABLED = 'scheduler.task_invalid_cron_disabled';
    public const SCHEDULER_TASK_SOFT_BUDGET_EXCEEDED = 'scheduler.task_soft_budget_exceeded';
}
