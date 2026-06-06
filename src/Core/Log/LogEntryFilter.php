<?php

declare(strict_types=1);

namespace App\Core\Log;

final readonly class LogEntryFilter
{
    private const DEFAULT_PER_PAGE = 50;

    /**
     * @param array<string, mixed> $query
     *
     * @return array{level: string, search: string, match: string, time_window: string, audit_action: string, per_page: int|string, page: int}
     */
    public function filters(array $query): array
    {
        return [
            'level' => $this->level($query['level'] ?? null),
            'search' => $this->search($query['q'] ?? null),
            'match' => $this->match($query['match'] ?? null),
            'time_window' => $this->timeWindow($query['time_window'] ?? null),
            'audit_action' => $this->search($query['audit_action'] ?? null),
            'per_page' => $this->perPage($query['per_page'] ?? null),
            'page' => $this->page($query['page'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $entry
     * @param array{level: string, search: string, match: string, time_window: string, audit_action: string, per_page: int|string, page: int} $filters
     */
    public function matches(array $entry, array $filters): bool
    {
        if ('' !== $filters['level'] && $entry['level'] !== $filters['level']) {
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

    private function level(mixed $level): string
    {
        if (!is_string($level)) {
            return '';
        }

        $level = strtoupper(trim($level));

        return in_array($level, ['DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'], true)
            ? $level
            : '';
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

    private function perPage(mixed $perPage): int|string
    {
        if ('all' === $perPage) {
            return 'all';
        }

        $perPage = is_numeric($perPage) ? (int) $perPage : self::DEFAULT_PER_PAGE;

        return in_array($perPage, [25, 50, 100, 150], true) ? $perPage : self::DEFAULT_PER_PAGE;
    }

    private function page(mixed $page): int
    {
        $page = is_numeric($page) ? (int) $page : 1;

        return max(1, $page);
    }
}
