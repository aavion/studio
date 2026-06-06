<?php

declare(strict_types=1);

namespace App\Core\Statistics;

use App\Core\Message\CommonMessageCode;
use App\Core\Message\Message;
use App\Core\Message\MessageReporterInterface;
use App\Core\Statistics\StatisticsMessageKey;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Throwable;

final readonly class AccessStatisticsAggregator
{
    private const TOP_LIMIT = 10;

    public function __construct(
        private Connection $connection,
        private AccessStatisticsWindow $window,
        private ?MessageReporterInterface $messageReporter = null,
    ) {
    }

    /**
     * @return array{
     *     generated_at: string,
     *     window: string,
     *     since: string|null,
     *     total_requests: int,
     *     unique_visitors: int,
     *     status_families: array<string, int>,
     *     top_routes: list<array{label: string, count: int}>,
     *     top_not_found: list<array{label: string, count: int}>,
     *     top_countries: list<array{label: string, count: int}>,
     *     top_browsers: list<array{label: string, count: int}>,
     *     device_types: list<array{label: string, count: int}>,
     *     bot_requests: int,
     *     do_not_track_requests: int,
     *     surfaces: list<array{label: string, count: int}>,
     *     top_referrers: list<array{label: string, count: int}>,
     *     languages: list<array{label: string, count: int}>,
     *     average_duration_ms: int|null,
     *     source_files: list<string>
     * }
     */
    public function snapshot(string $window = AccessStatisticsWindow::DEFAULT): array
    {
        $window = $this->window->normalize($window);
        $since = $this->window->since($window);
        $summary = $this->summary($since);

        return [
            'generated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'window' => $window,
            'since' => $since?->format(DATE_ATOM),
            'total_requests' => $summary['total_requests'],
            'unique_visitors' => $summary['unique_visitors'],
            'status_families' => $summary['status_families'],
            'top_routes' => $this->topRoutes($since),
            'top_not_found' => $this->topRoutes($since, 404),
            'top_countries' => $this->topField($since, 'country', 'n/a'),
            'top_browsers' => $this->topField($since, 'browser_family', 'other'),
            'device_types' => $this->topField($since, 'device_type', 'other'),
            'bot_requests' => $summary['bot_requests'],
            'do_not_track_requests' => $summary['do_not_track_requests'],
            'surfaces' => $this->topField($since, 'surface', 'public'),
            'top_referrers' => $this->topField($since, 'referrer_host', 'n/a', skipPlaceholder: true),
            'languages' => $this->topField($since, 'preferred_language', 'n/a'),
            'average_duration_ms' => $summary['average_duration_ms'],
            'source_files' => [],
        ];
    }

    /**
     * @return array{
     *     total_requests: int,
     *     unique_visitors: int,
     *     status_families: array<string, int>,
     *     bot_requests: int,
     *     do_not_track_requests: int,
     *     average_duration_ms: int|null
     * }
     */
    private function summary(?DateTimeImmutable $since): array
    {
        try {
            [$where, $parameters] = $this->where($since);
            $base = $this->connection->fetchAssociative(
                'SELECT COUNT(*) AS total_requests, COUNT(DISTINCT visitor_id) AS unique_visitors, SUM(CASE WHEN is_bot THEN 1 ELSE 0 END) AS bot_requests, SUM(CASE WHEN do_not_track THEN 1 ELSE 0 END) AS do_not_track_requests, AVG(duration_ms) AS average_duration_ms FROM access_statistic_event'.$where,
                $parameters,
            ) ?: [];
            $statusFamilies = ['2xx' => 0, '3xx' => 0, '4xx' => 0, '5xx' => 0, 'other' => 0];

            foreach ($this->connection->fetchAllAssociative(
                'SELECT http_status, COUNT(*) AS count FROM access_statistic_event'.$where.' GROUP BY http_status',
                $parameters,
            ) as $row) {
                $statusFamilies[$this->statusFamily($this->intValue($row, 'http_status'))] += $this->intValue($row, 'count');
            }

            return [
                'total_requests' => $this->intValue($base, 'total_requests'),
                'unique_visitors' => $this->intValue($base, 'unique_visitors'),
                'status_families' => $statusFamilies,
                'bot_requests' => $this->intValue($base, 'bot_requests'),
                'do_not_track_requests' => $this->intValue($base, 'do_not_track_requests'),
                'average_duration_ms' => $this->nullableIntValue($base, 'average_duration_ms'),
            ];
        } catch (Throwable $error) {
            $this->report($error, $since);

            return [
                'total_requests' => 0,
                'unique_visitors' => 0,
                'status_families' => ['2xx' => 0, '3xx' => 0, '4xx' => 0, '5xx' => 0, 'other' => 0],
                'bot_requests' => 0,
                'do_not_track_requests' => 0,
                'average_duration_ms' => null,
            ];
        }
    }

    /**
     * @return list<array{label: string, count: int}>
     */
    private function topRoutes(?DateTimeImmutable $since, ?int $status = null): array
    {
        try {
            [$where, $parameters] = $this->where($since);

            if (null !== $status) {
                $where .= '' === $where ? ' WHERE http_status = ?' : ' AND http_status = ?';
                $parameters[] = $status;
            }

            $counts = [];

            foreach ($this->connection->fetchAllAssociative(
                'SELECT method, path, requested_path, route, resolved_route, COUNT(*) AS count FROM access_statistic_event'.$where.' GROUP BY method, path, requested_path, route, resolved_route',
                $parameters,
            ) as $row) {
                $label = $this->routeLabel($row);
                $counts[$label] = ($counts[$label] ?? 0) + $this->intValue($row, 'count');
            }

            return $this->top($counts);
        } catch (Throwable $error) {
            $this->report($error, $since);

            return [];
        }
    }

    /**
     * @return list<array{label: string, count: int}>
     */
    private function topField(?DateTimeImmutable $since, string $field, string $fallback, bool $skipPlaceholder = false): array
    {
        try {
            [$where, $parameters] = $this->where($since);
            $rows = $this->connection->fetchAllAssociative(
                sprintf(
                    'SELECT %s AS label, COUNT(*) AS count FROM access_statistic_event%s%s GROUP BY %s ORDER BY count DESC LIMIT %d',
                    $field,
                    $where,
                    $skipPlaceholder ? ('' === $where ? ' WHERE '.$field.' <> ?' : ' AND '.$field.' <> ?') : '',
                    $field,
                    self::TOP_LIMIT,
                ),
                $skipPlaceholder ? [...$parameters, $fallback] : $parameters,
            );
        } catch (Throwable $error) {
            $this->report($error, $since);

            return [];
        }

        return array_map(fn (array $row): array => [
            'label' => $this->stringValue($row, 'label', $fallback),
            'count' => $this->intValue($row, 'count'),
        ], $rows);
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function where(?DateTimeImmutable $since): array
    {
        if (null === $since) {
            return ['', []];
        }

        return [' WHERE occurred_at >= ?', [$since->format('Y-m-d H:i:s')]];
    }

    private function report(Throwable $error, ?DateTimeImmutable $since): void
    {
        $this->messageReporter?->report(Message::exception(
            CommonMessageCode::E_OPERATION_FAILED,
            StatisticsMessageKey::STATISTICS_AGGREGATE_FAILED,
            [],
            [
                'operation' => 'statistics.aggregate',
                'since' => $since?->format(DATE_ATOM),
                'exception' => $error::class,
                'message' => $error->getMessage(),
            ],
        ), [
            'operation' => 'statistics.aggregate',
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function routeLabel(array $row): string
    {
        $route = $this->stringValue($row, 'resolved_route', '');

        if ('' === $route || 'n/a' === $route) {
            $route = $this->stringValue($row, 'route', '');
        }

        if ('' !== $route && 'n/a' !== $route) {
            return $route;
        }

        return $this->stringValue($row, 'method', 'GET').' '.$this->stringValue($row, 'requested_path', $this->stringValue($row, 'path', '/'));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function intValue(array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function nullableIntValue(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function stringValue(array $row, string $key, string $fallback): string
    {
        $value = $row[$key] ?? null;

        return is_string($value) && '' !== trim($value) ? $value : $fallback;
    }

    private function statusFamily(int $status): string
    {
        return match (intdiv(max(0, $status), 100)) {
            2 => '2xx',
            3 => '3xx',
            4 => '4xx',
            5 => '5xx',
            default => 'other',
        };
    }

    /**
     * @param array<string, int> $counts
     *
     * @return list<array{label: string, count: int}>
     */
    private function top(array $counts): array
    {
        arsort($counts);
        $rows = [];

        foreach (array_slice($counts, 0, self::TOP_LIMIT, true) as $label => $count) {
            $rows[] = ['label' => $label, 'count' => $count];
        }

        return $rows;
    }
}
