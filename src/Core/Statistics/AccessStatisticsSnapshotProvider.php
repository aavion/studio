<?php

declare(strict_types=1);

namespace App\Core\Statistics;

final readonly class AccessStatisticsSnapshotProvider
{
    public function __construct(
        private AccessStatisticsAggregator $aggregator,
        private AccessStatisticsStoreInterface $store,
        private AccessStatisticsWindow $window,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(mixed $window = AccessStatisticsWindow::DEFAULT): array
    {
        $snapshot = $this->aggregator->snapshot($this->window->normalize($window));

        if ($this->store->saveLatest($snapshot)) {
            return $this->store->latest() ?? $snapshot;
        }

        return $snapshot;
    }

    /**
     * @return list<array{key: string, label_key: string}>
     */
    public function windows(): array
    {
        return $this->window->options();
    }
}
