<?php

declare(strict_types=1);

namespace App\Scheduler;

final readonly class SchedulerFailurePolicy
{
    public function disableAfterFailures(): int
    {
        return 3;
    }

    public function invalidCronDisableAfterFailures(): int
    {
        return 1;
    }
}
