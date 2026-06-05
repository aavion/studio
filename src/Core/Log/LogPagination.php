<?php

declare(strict_types=1);

namespace App\Core\Log;

final readonly class LogPagination
{
    /**
     * @param array{level: string, search: string, match: string, time_window: string, audit_action: string, per_page: int|string, page: int} $filters
     *
     * @return array{page: int, per_page: int|string, total: int, total_pages: int, has_previous: bool, has_next: bool, previous_page: int, next_page: int}
     */
    public function pagination(array $filters, int $matched): array
    {
        if ('all' === $filters['per_page']) {
            return [
                'page' => 1,
                'per_page' => 'all',
                'total' => $matched,
                'total_pages' => 1,
                'has_previous' => false,
                'has_next' => false,
                'previous_page' => 1,
                'next_page' => 1,
            ];
        }

        $perPage = (int) $filters['per_page'];
        $totalPages = max(1, (int) ceil($matched / $perPage));
        $page = min($filters['page'], $totalPages);

        return [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $matched,
            'total_pages' => $totalPages,
            'has_previous' => $page > 1,
            'has_next' => $page < $totalPages,
            'previous_page' => max(1, $page - 1),
            'next_page' => min($totalPages, $page + 1),
        ];
    }

    /**
     * @return list<array{key: int|string, label: string}>
     */
    public function perPageOptions(): array
    {
        return [
            ['key' => 25, 'label' => '25'],
            ['key' => 50, 'label' => '50'],
            ['key' => 100, 'label' => '100'],
            ['key' => 150, 'label' => '150'],
            ['key' => 'all', 'label' => 'admin.logs.filters.all_entries'],
        ];
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public function timeWindowOptions(): array
    {
        return [
            ['key' => '1h', 'label' => 'admin.logs.filters.windows.1h'],
            ['key' => '24h', 'label' => 'admin.logs.filters.windows.24h'],
            ['key' => '7d', 'label' => 'admin.logs.filters.windows.7d'],
            ['key' => '30d', 'label' => 'admin.logs.filters.windows.30d'],
        ];
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public function matchOptions(): array
    {
        return [
            ['key' => 'contains', 'label' => 'admin.logs.filters.contains'],
            ['key' => 'equals', 'label' => 'admin.logs.filters.equals'],
        ];
    }
}
