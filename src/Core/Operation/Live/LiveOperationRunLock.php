<?php

declare(strict_types=1);

namespace App\Core\Operation\Live;

use Symfony\Component\Lock\LockInterface;
use Throwable;

final class LiveOperationRunLock
{
    private bool $released = false;

    public function __construct(
        private readonly LiveOperationRunStore $store,
        private readonly string $owner,
        private readonly LockInterface $lock,
        private readonly int $ttlSeconds,
    ) {
    }

    public function touch(): void
    {
        if ($this->released) {
            return;
        }

        $this->store->touchRunnerLock($this->owner);

        try {
            $this->lock->refresh($this->ttlSeconds);
        } catch (Throwable) {
        }
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;
        $this->store->releaseRunnerLock($this->owner);

        try {
            $this->lock->release();
        } catch (Throwable) {
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
