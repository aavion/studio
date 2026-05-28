<?php

declare(strict_types=1);

namespace App\Core\Log;

use SplFileObject;

final readonly class LogFileBrowser
{
    private const MAX_SCAN_LINES = 5000;
    private const DEFAULT_PER_PAGE = 50;

    /**
     * @var array<string, array{label: string, pattern: string}>
     */
    private const SOURCES = [
        'application' => ['label' => 'admin.logs.sources.application', 'pattern' => '%env%.log'],
        'message' => ['label' => 'admin.logs.sources.message', 'pattern' => '%env%.studio-message-*.log'],
        'audit' => ['label' => 'admin.logs.sources.audit', 'pattern' => '%env%.studio-audit-*.log'],
        'access' => ['label' => 'admin.logs.sources.access', 'pattern' => '%env%.studio-access-*.log'],
    ];

    public function __construct(
        private string $logDir,
        private string $environment,
        private MonologLineParser $lineParser = new MonologLineParser(),
    ) {
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    public function browse(array $query): array
    {
        $source = $this->source($query['source'] ?? null);
        $filters = [
            'level' => $this->level($query['level'] ?? null),
            'search' => $this->search($query['q'] ?? null),
            'match' => $this->match($query['match'] ?? null),
            'time_window' => $this->timeWindow($query['time_window'] ?? null),
            'audit_action' => $this->search($query['audit_action'] ?? null),
            'per_page' => $this->perPage($query['per_page'] ?? null),
            'page' => $this->page($query['page'] ?? null),
        ];
        $files = $this->files($source);
        $entries = [];
        $matched = 0;
        $offset = 'all' === $filters['per_page'] ? 0 : ($filters['page'] - 1) * (int) $filters['per_page'];
        $limit = 'all' === $filters['per_page'] ? PHP_INT_MAX : (int) $filters['per_page'];

        foreach ($files as $file) {
            foreach ($this->readLines($file) as $line) {
                $entry = $this->lineParser->parse($line, $file);
                $entry['id'] = $this->entryId($source, $entry);
                $entry['source'] = $source;
                $entry['summary'] = $this->summary($source, $entry);

                if (!$this->matches($entry, $filters)) {
                    continue;
                }

                ++$matched;

                if ($matched <= $offset) {
                    continue;
                }

                if (count($entries) < $limit) {
                    $entries[] = $entry;
                }
            }
        }

        return [
            'sources' => $this->sourceOptions(),
            'selected_source' => $source,
            'filters' => $filters,
            'entries' => $entries,
            'files' => array_map('basename', $files),
            'pagination' => $this->pagination($filters, $matched),
            'per_page_options' => $this->perPageOptions(),
            'time_window_options' => $this->timeWindowOptions(),
            'match_options' => $this->matchOptions(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function entry(string $source, string $id): ?array
    {
        $source = $this->source($source);

        foreach ($this->files($source) as $file) {
            foreach ($this->readLines($file) as $line) {
                $entry = $this->lineParser->parse($line, $file);
                $entry['id'] = $this->entryId($source, $entry);

                if ($entry['id'] !== $id) {
                    continue;
                }

                if (!$this->matchesTimeWindow($entry, '30d')) {
                    continue;
                }

                $entry['source'] = $source;
                $entry['summary'] = $this->summary($source, $entry);

                return $entry;
            }
        }

        return null;
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function sourceOptions(): array
    {
        $options = [];

        foreach (self::SOURCES as $key => $source) {
            $options[] = ['key' => $key, 'label' => $source['label']];
        }

        return $options;
    }

    private function source(mixed $source): string
    {
        return is_string($source) && isset(self::SOURCES[$source]) ? $source : 'message';
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

    /**
     * @return list<string>
     */
    private function files(string $source): array
    {
        $pattern = $this->logDir.'/'.str_replace('%env%', $this->environment, self::SOURCES[$source]['pattern']);
        $files = glob($pattern) ?: [];
        $files = array_values(array_filter($files, 'is_file'));

        usort($files, static fn (string $left, string $right): int => [
            filemtime($right) ?: 0,
            basename($right),
        ] <=> [
            filemtime($left) ?: 0,
            basename($left),
        ]);

        return $files;
    }

    /**
     * @return list<string>
     */
    private function readLines(string $file): array
    {
        $object = new SplFileObject($file, 'r');
        $object->seek(PHP_INT_MAX);
        $lastLine = $object->key();
        $start = max(0, $lastLine - self::MAX_SCAN_LINES);
        $lines = [];

        for ($lineNumber = $lastLine; $lineNumber >= $start; --$lineNumber) {
            $object->seek($lineNumber);
            $line = trim((string) $object->current());

            if ('' !== $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $entry
     * @param array{level: string, search: string, match: string, time_window: string, audit_action: string, per_page: int|string, page: int} $filters
     */
    private function matches(array $entry, array $filters): bool
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

    private function matchesTimeWindow(array $entry, string $window): bool
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

    private function entryId(string $source, array $entry): string
    {
        return substr(hash('sha256', $source."\0".($entry['file'] ?? '')."\0".($entry['raw'] ?? '')), 0, 24);
    }

    private function summary(string $source, array $entry): string
    {
        $context = is_array($entry['context'] ?? null) ? $entry['context'] : [];

        return match ($source) {
            'access' => trim(($context['method'] ?? 'n/a').' '.($context['requested_path'] ?? $context['path'] ?? 'n/a')),
            'audit' => (string) ($context['action'] ?? $entry['message'] ?? 'n/a'),
            default => (string) ($entry['message'] ?? 'n/a'),
        };
    }

    /**
     * @param array{level: string, search: string, match: string, time_window: string, audit_action: string, per_page: int|string, page: int} $filters
     *
     * @return array{page: int, per_page: int|string, total: int, total_pages: int, has_previous: bool, has_next: bool, previous_page: int, next_page: int}
     */
    private function pagination(array $filters, int $matched): array
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
    private function perPageOptions(): array
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
    private function timeWindowOptions(): array
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
    private function matchOptions(): array
    {
        return [
            ['key' => 'contains', 'label' => 'admin.logs.filters.contains'],
            ['key' => 'equals', 'label' => 'admin.logs.filters.equals'],
        ];
    }
}
