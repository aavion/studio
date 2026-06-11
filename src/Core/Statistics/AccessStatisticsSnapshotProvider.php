<?php

declare(strict_types=1);

namespace App\Core\Statistics;

use App\Core\Message\CommonMessageCode;
use App\Core\Message\Message;
use App\Core\Message\MessageReporterInterface;
use App\Core\Statistics\StatisticsMessageKey;

final readonly class AccessStatisticsSnapshotProvider
{
    public function __construct(
        private AccessStatisticsAggregator $aggregator,
        private AccessStatisticsStoreInterface $store,
        private AccessStatisticsWindow $window,
        private ?AccessStatisticsPolicy $policy = null,
        private ?MessageReporterInterface $messageReporter = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(mixed $window = AccessStatisticsWindow::DEFAULT): array
    {
        return $this->snapshotWithStorageStatus($window)['snapshot'];
    }

    /**
     * @return array{snapshot: array<string, mixed>, stored: bool}
     */
    public function snapshotWithStorageStatus(mixed $window = AccessStatisticsWindow::DEFAULT): array
    {
        if (null !== $this->policy && !$this->policy->isDisplayEnabled()) {
            return [
                'snapshot' => $this->disabledSnapshot($this->window->normalize($window)),
                'stored' => true,
            ];
        }

        $snapshot = $this->aggregator->snapshot($this->window->normalize($window));

        if ($this->store->saveLatest($snapshot)) {
            return [
                'snapshot' => $this->store->latest() ?? $snapshot,
                'stored' => true,
            ];
        }

        $this->messageReporter?->report(Message::warning(
            CommonMessageCode::E_OPERATION_FAILED,
            StatisticsMessageKey::STATISTICS_SNAPSHOT_STORE_FAILED,
            [],
            [
                'operation' => 'statistics.snapshot.store',
                'window' => $snapshot['window'] ?? $this->window->normalize($window),
            ],
        ), [
            'operation' => 'statistics.snapshot.store',
        ]);

        return [
            'snapshot' => $snapshot,
            'stored' => false,
        ];
    }

    /**
     * @return list<array{key: string, label_key: string}>
     */
    public function windows(): array
    {
        return $this->window->options();
    }

    /**
     * @return array<string, mixed>
     */
    private function disabledSnapshot(string $window): array
    {
        return [
            'enabled' => false,
            'generated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'window' => $window,
            'since' => $this->window->since($window)?->format(DATE_ATOM),
            'total_requests' => 0,
            'page_requests' => 0,
            'api_requests' => 0,
            'unique_visitors' => 0,
            'status_families' => ['2xx' => 0, '3xx' => 0, '4xx' => 0, '5xx' => 0, 'other' => 0],
            'top_routes' => [],
            'top_not_found' => [],
            'top_countries' => [],
            'top_browsers' => [],
            'device_types' => [],
            'bot_requests' => 0,
            'do_not_track_requests' => 0,
            'surfaces' => [],
            'top_referrers' => [],
            'languages' => [],
            'average_duration_ms' => null,
            'source_files' => [],
        ];
    }
}
