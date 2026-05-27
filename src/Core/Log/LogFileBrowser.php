<?php

declare(strict_types=1);

namespace App\Core\Log;

use SplFileObject;

final readonly class LogFileBrowser
{
    private const DEFAULT_LIMIT = 100;
    private const MAX_LIMIT = 500;
    private const MAX_SCAN_LINES = 5000;

    /**
     * @var array<string, array{label: string, pattern: string}>
     */
    private const SOURCES = [
        'application' => ['label' => 'admin.logs.sources.application', 'pattern' => '%env%.log'],
        'message' => ['label' => 'admin.logs.sources.message', 'pattern' => '%env%.studio-message-*.log'],
        'operation' => ['label' => 'admin.logs.sources.operation', 'pattern' => '%env%.studio-operation-*.log'],
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
     * @return array{sources: list<array{key: string, label: string}>, selected_source: string, filters: array{level: string, search: string, limit: int}, entries: list<array<string, mixed>>, files: list<string>}
     */
    public function browse(array $query): array
    {
        $source = $this->source($query['source'] ?? null);
        $filters = [
            'level' => $this->level($query['level'] ?? null),
            'search' => $this->search($query['q'] ?? null),
            'limit' => $this->limit($query['limit'] ?? null),
        ];
        $files = $this->files($source);
        $entries = [];

        foreach ($files as $file) {
            foreach ($this->readLines($file) as $line) {
                $entry = $this->lineParser->parse($line, $file);

                if (!$this->matches($entry, $filters)) {
                    continue;
                }

                $entries[] = $entry;

                if (count($entries) >= $filters['limit']) {
                    break 2;
                }
            }
        }

        return [
            'sources' => $this->sourceOptions(),
            'selected_source' => $source,
            'filters' => $filters,
            'entries' => $entries,
            'files' => array_map('basename', $files),
        ];
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

    private function limit(mixed $limit): int
    {
        $limit = is_numeric($limit) ? (int) $limit : self::DEFAULT_LIMIT;

        return min(self::MAX_LIMIT, max(1, $limit));
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
     * @param array{level: string, search: string, limit: int} $filters
     */
    private function matches(array $entry, array $filters): bool
    {
        if ('' !== $filters['level'] && $entry['level'] !== $filters['level']) {
            return false;
        }

        return '' === $filters['search'] || str_contains(mb_strtolower($entry['raw']), mb_strtolower($filters['search']));
    }
}
