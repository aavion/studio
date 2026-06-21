<?php

declare(strict_types=1);

namespace App\Security\AutoBan;

final readonly class AutoBanResetService
{
    public function __construct(
        private AutoBanStore $store,
    ) {
    }

    /**
     * @param callable(ActiveAutoBan): bool $recordResetSignal
     */
    public function releaseAndRecord(string $key, callable $recordResetSignal): ?ActiveAutoBan
    {
        return $this->store->resetAndRecord($key, $recordResetSignal);
    }
}
