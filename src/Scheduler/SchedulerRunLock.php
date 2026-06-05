<?php

declare(strict_types=1);

namespace App\Scheduler;

use Symfony\Component\Lock\LockInterface;

final class SchedulerRunLock
{
    private ?LockInterface $lock;

    public function __construct(LockInterface $lock)
    {
        $this->lock = $lock;
    }

    public function release(): void
    {
        $this->lock?->release();

        $this->lock = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
