<?php

declare(strict_types=1);

namespace App\Scheduler;

enum SchedulerTaskRunStatus: string
{
    case Running = 'running';
    case Success = 'success';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
