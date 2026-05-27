<?php

declare(strict_types=1);

namespace App\Core\Statistics;

final readonly class AccessStatisticsSnapshotProvider
{
    public function __construct(
        private AccessStatisticsAggregator $aggregator,
        private AccessStatisticsStoreInterface $store,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $snapshot = $this->aggregator->snapshot();

        if ($this->store->saveLatest($snapshot)) {
            return $this->store->latest() ?? $snapshot;
        }

        return $snapshot;
    }
}
