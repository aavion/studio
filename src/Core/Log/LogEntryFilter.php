<?php

declare(strict_types=1);

namespace App\Core\Log;

final readonly class LogEntryFilter
{
    private const DEFAULT_PER_PAGE = 50;
    private const DEFAULT_LEVELS = ['NOTICE', 'WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];
    private const LEVELS = ['DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];

    /**
     * @param array<string, mixed> $query
     *
     * @return array{level: string, levels: list<string>, search: string, match: string, time_window: string, audit_action: string, per_page: int, page: int}
     */
    public function filters(array $query): array
    {
        $levels = $this->levels($query['level'] ?? $query['levels'] ?? null);

        return [
            'level' => 1 === count($levels) ? $levels[0] : '',
            'levels' => $levels,
            'search' => $this->search($query['q'] ?? null),
            'match' => $this->match($query['match'] ?? null),
            'time_window' => $this->timeWindow($query['time_window'] ?? null),
            'audit_action' => $this->search($query['audit_action'] ?? null),
            'per_page' => $this->perPage($query['per_page'] ?? null),
            'page' => $this->page($query['page'] ?? null),
        ];
    }

    /**
     * @param array{level: string, levels: list<string>, search: string, match: string, time_window: string, audit_action: string, per_page: int, page: int} $filters
     *
     * @return array{level: string, levels: list<string>, search: string, match: string, time_window: string, audit_action: string, per_page: int, page: int}
     */
    public function filtersForSource(array $filters, bool $supportsLevelFilter, bool $supportsAuditActionFilter): array
    {
        if (!$supportsLevelFilter) {
            $filters['level'] = '';
            $filters['levels'] = [];
        }

        if (!$supportsAuditActionFilter) {
            $filters['audit_action'] = '';
        }

        return $filters;
    }

    /**
     * @param array<string, mixed> $entry
     * @param array{level: string, levels: list<string>, search: string, match: string, time_window: string, audit_action: string, per_page: int, page: int} $filters
     */
    public function matches(array $entry, array $filters): bool
    {
        if ([] !== $filters['levels'] && !in_array($entry['level'], $filters['levels'], true)) {
            return false;
        }

        if (!$this->matchesTimeWindow($entry, $filters['time_window'])) {
            return false;
        }

        if ('' !== $filters['audit_action'] && ($entry['context']['action'] ?? $entry['message']) !== $filters['audit_action']) {
            return false;
        }

        if ('' === $filters['search']) {
            return true;
        }

        $haystack = mb_strtolower($entry['raw']);
        $needle = mb_strtolower($filters['search']);

        return 'equals' === $filters['match'] ? $haystack === $needle : str_contains($haystack, $needle);
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function matchesTimeWindow(array $entry, string $window): bool
    {
        $timestamp = is_string($entry['timestamp'] ?? null) ? strtotime($entry['timestamp']) : false;

        if (false === $timestamp) {
            return true;
        }

        $cutoff = match ($window) {
            '1h' => time() - 3600,
            '24h' => time() - 86400,
            '7d' => time() - 604800,
            default => time() - 2592000,
        };

        return $timestamp >= $cutoff;
    }

    /**
     * @return list<string>
     */
    private function levels(mixed $level): array
    {
        if (null === $level || '' === $level || [] === $level) {
            return self::DEFAULT_LEVELS;
        }

        $levels = is_array($level) ? $level : [$level];
        $levels = array_values(array_unique(array_filter(array_map(
            static fn (mixed $candidate): string => is_string($candidate) ? strtoupper(trim($candidate)) : '',
            $levels,
        ))));

        $levels = array_values(array_filter($levels, static fn (string $candidate): bool => in_array($candidate, self::LEVELS, true)));

        return [] === $levels ? self::DEFAULT_LEVELS : $levels;
    }

    private function search(mixed $search): string
    {
        return is_string($search) ? mb_substr(trim($search), 0, 120) : '';
    }

    private function match(mixed $match): string
    {
        return 'equals' === $match ? 'equals' : 'contains';
    }

    private function timeWindow(mixed $window): string
    {
        return in_array($window, ['1h', '24h', '7d', '30d'], true) ? $window : '24h';
    }

    private function perPage(mixed $perPage): int
    {
        if ('all' === $perPage) {
            return 500;
        }

        $perPage = is_numeric($perPage) ? (int) $perPage : self::DEFAULT_PER_PAGE;

        return in_array($perPage, [25, 50, 100, 150, 500], true) ? $perPage : self::DEFAULT_PER_PAGE;
    }

    private function page(mixed $page): int
    {
        $page = is_numeric($page) ? (int) $page : 1;

        return max(1, $page);
    }
}
