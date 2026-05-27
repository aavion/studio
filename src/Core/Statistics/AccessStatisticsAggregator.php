<?php

declare(strict_types=1);

namespace App\Core\Statistics;

use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageReporterInterface;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Throwable;

final readonly class AccessStatisticsAggregator
{
    private const MAX_ROWS = 10000;
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
        $total = 0;
        $statusFamilies = ['2xx' => 0, '3xx' => 0, '4xx' => 0, '5xx' => 0, 'other' => 0];
        $routes = [];
        $notFound = [];
        $countries = [];
        $browsers = [];
        $devices = [];
        $surfaces = [];
        $referrers = [];
        $languages = [];
        $botRequests = 0;
        $doNotTrackRequests = 0;
        $durationSum = 0;
        $durationCount = 0;
        $visitors = [];

        foreach ($this->rows($since) as $row) {
            ++$total;
            $visitorId = $this->stringValue($row, 'visitor_id', '');

            if ('' !== $visitorId) {
                $visitors[$visitorId] = true;
            }

            $status = $this->intValue($row, 'http_status');
            $family = $this->statusFamily($status);
            ++$statusFamilies[$family];

            $route = $this->routeLabel($row);
            $routes[$route] = ($routes[$route] ?? 0) + 1;

            if (404 === $status) {
                $notFound[$route] = ($notFound[$route] ?? 0) + 1;
            }

            $country = $this->stringValue($row, 'country', 'n/a');
            $countries[$country] = ($countries[$country] ?? 0) + 1;

            $browser = $this->stringValue($row, 'browser_family', 'other');
            $browsers[$browser] = ($browsers[$browser] ?? 0) + 1;

            $device = $this->stringValue($row, 'device_type', 'other');
            $devices[$device] = ($devices[$device] ?? 0) + 1;

            if ($this->boolValue($row, 'is_bot')) {
                ++$botRequests;
            }

            if ($this->boolValue($row, 'do_not_track')) {
                ++$doNotTrackRequests;
            }

            $surface = $this->stringValue($row, 'surface', 'public');
            $surfaces[$surface] = ($surfaces[$surface] ?? 0) + 1;

            $referrer = $this->stringValue($row, 'referrer_host', 'n/a');

            if ('n/a' !== $referrer) {
                $referrers[$referrer] = ($referrers[$referrer] ?? 0) + 1;
            }

            $language = $this->stringValue($row, 'preferred_language', 'n/a');
            $languages[$language] = ($languages[$language] ?? 0) + 1;

            $durationMs = $this->nullableIntValue($row, 'duration_ms');

            if (null !== $durationMs) {
                $durationSum += $durationMs;
                ++$durationCount;
            }
        }

        return [
            'generated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'window' => $window,
            'since' => $since?->format(DATE_ATOM),
            'total_requests' => $total,
            'unique_visitors' => count($visitors),
            'status_families' => $statusFamilies,
            'top_routes' => $this->top($routes),
            'top_not_found' => $this->top($notFound),
            'top_countries' => $this->top($countries),
            'top_browsers' => $this->top($browsers),
            'device_types' => $this->top($devices),
            'bot_requests' => $botRequests,
            'do_not_track_requests' => $doNotTrackRequests,
            'surfaces' => $this->top($surfaces),
            'top_referrers' => $this->top($referrers),
            'languages' => $this->top($languages),
            'average_duration_ms' => $durationCount > 0 ? (int) round($durationSum / $durationCount) : null,
            'source_files' => [],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(?DateTimeImmutable $since): array
    {
        try {
            if (null !== $since) {
                return $this->connection->fetchAllAssociative(
                    'SELECT visitor_id, method, path, requested_path, route, resolved_route, surface, http_status, duration_ms, browser_family, device_type, is_bot, do_not_track, referrer_host, preferred_language, country FROM access_statistic_event WHERE occurred_at >= ? ORDER BY occurred_at DESC LIMIT '.self::MAX_ROWS,
                    [$since->format('Y-m-d H:i:s')],
                );
            }

            return $this->connection->fetchAllAssociative(
                'SELECT visitor_id, method, path, requested_path, route, resolved_route, surface, http_status, duration_ms, browser_family, device_type, is_bot, do_not_track, referrer_host, preferred_language, country FROM access_statistic_event ORDER BY occurred_at DESC LIMIT '.self::MAX_ROWS,
            );
        } catch (Throwable $error) {
            $this->messageReporter?->report(Message::exception(
                MessageCode::E_OPERATION_FAILED,
                MessageKey::STATISTICS_AGGREGATE_FAILED,
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

            return [];
        }
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

    /**
     * @param array<string, mixed> $row
     */
    private function boolValue(array $row, string $key): bool
    {
        $value = $row[$key] ?? false;

        return true === $value || 1 === $value || '1' === $value || 'true' === $value;
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
