<?php

declare(strict_types=1);

namespace App\Scheduler;

enum SchedulerTaskStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Faulty = 'faulty';
}
