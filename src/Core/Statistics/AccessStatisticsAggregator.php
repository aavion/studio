<?php

declare(strict_types=1);

namespace App\Core\Statistics;

use Doctrine\DBAL\Connection;
use Throwable;

final readonly class AccessStatisticsAggregator
{
    private const MAX_ROWS = 10000;
    private const TOP_LIMIT = 10;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return array{
     *     generated_at: string,
     *     total_requests: int,
     *     unique_visitors: int,
     *     status_families: array<string, int>,
     *     top_routes: list<array{label: string, count: int}>,
     *     top_not_found: list<array{label: string, count: int}>,
     *     top_countries: list<array{label: string, count: int}>,
     *     source_files: list<string>
     * }
     */
    public function snapshot(): array
    {
        $total = 0;
        $statusFamilies = ['2xx' => 0, '3xx' => 0, '4xx' => 0, '5xx' => 0, 'other' => 0];
        $routes = [];
        $notFound = [];
        $countries = [];
        $visitors = [];

        foreach ($this->rows() as $row) {
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
        }

        return [
            'generated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'total_requests' => $total,
            'unique_visitors' => count($visitors),
            'status_families' => $statusFamilies,
            'top_routes' => $this->top($routes),
            'top_not_found' => $this->top($notFound),
            'top_countries' => $this->top($countries),
            'source_files' => [],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        try {
            return $this->connection->fetchAllAssociative(
                'SELECT visitor_id, method, path, route, http_status, country FROM access_statistic_event ORDER BY occurred_at DESC LIMIT '.self::MAX_ROWS,
            );
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function routeLabel(array $row): string
    {
        $route = $this->stringValue($row, 'route', '');

        if ('' !== $route && 'n/a' !== $route) {
            return $route;
        }

        return $this->stringValue($row, 'method', 'GET').' '.$this->stringValue($row, 'path', '/');
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
