<?php

declare(strict_types=1);

namespace App\Core\Operation\Live;

final class LiveOperationRunLock
{
    private bool $released = false;

    public function __construct(
        private readonly LiveOperationRunStore $store,
        private readonly string $owner,
    ) {
    }

    public function touch(): void
    {
        if ($this->released) {
            return;
        }

        $this->store->touchRunnerLock($this->owner);
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;
        $this->store->releaseRunnerLock($this->owner);
    }

    public function __destruct()
    {
        $this->release();
    }
}
