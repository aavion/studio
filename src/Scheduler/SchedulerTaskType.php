<?php

declare(strict_types=1);

namespace App\Scheduler;

enum SchedulerTaskType: string
{
    case ActionQueue = 'action_queue';
    case Callable = 'callable';
    case Command = 'command';
}
