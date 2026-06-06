<?php

declare(strict_types=1);

namespace App\Scheduler;

use Symfony\Component\Lock\LockFactory;

final readonly class SchedulerLockFactory
{
    public function __construct(private LockFactory $lockFactory, private string $environment)
    {
    }

    public function acquire(string $identifier = 'run'): ?SchedulerRunLock
    {
        $lock = $this->lockFactory->createLock('system.scheduler.'.$this->environment.'.'.$this->normalizeIdentifier($identifier));

        if (!$lock->acquire(false)) {
            return null;
        }

        return new SchedulerRunLock($lock);
    }

    private function normalizeIdentifier(string $identifier): string
    {
        return preg_replace('/[^a-zA-Z0-9_.-]+/', '_', $identifier) ?? 'run';
    }
}
