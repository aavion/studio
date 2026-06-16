<?php

declare(strict_types=1);

namespace App\Core\Log;

final readonly class LogFileBrowser
{
    public function __construct(
        private string $logDir,
        private string $environment,
        private MonologLineParser $lineParser = new MonologLineParser(),
        private LogSourceRegistry $sourceRegistry = new LogSourceRegistry(),
        private LogLineReader $lineReader = new LogLineReader(),
        private LogEntryFilter $entryFilter = new LogEntryFilter(),
        private LogEntryPresenter $entryPresenter = new LogEntryPresenter(),
        private LogPagination $pagination = new LogPagination(),
    ) {
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    public function browse(array $query): array
    {
        $source = $this->sourceRegistry->source($query['source'] ?? null);
        $filters = $this->entryFilter->filters($query);
        $filters = $this->entryFilter->filtersForSource(
            $filters,
            !in_array($source, ['access', 'audit'], true),
            'audit' === $source,
        );
        $files = $this->sourceRegistry->files($this->logDir, $this->environment, $source);
        $matched = $this->countMatches($source, $files, $filters);
        $pagination = $this->pagination->pagination($filters, $matched);
        $filters['page'] = $pagination['page'];
        $entries = $this->readPage($source, $files, $filters);

        return [
            'sources' => $this->sourceRegistry->sourceOptions(),
            'selected_source' => $source,
            'filters' => $filters,
            'entries' => $entries,
            'files' => array_map('basename', $files),
            'pagination' => $pagination,
            'per_page_options' => $this->pagination->perPageOptions(),
            'time_window_options' => $this->pagination->timeWindowOptions(),
            'match_options' => $this->pagination->matchOptions(),
        ];
    }

    /**
     * @param list<string> $files
     * @param array{level: string, levels: list<string>, search: string, match: string, time_window: string, audit_action: string, per_page: int, page: int} $filters
     */
    private function countMatches(string $source, array $files, array $filters): int
    {
        $matched = 0;

        foreach ($files as $file) {
            foreach ($this->lineReader->readLines($file) as $line) {
                if ($this->entryFilter->matches($this->entryPresenter->enrich($source, $this->lineParser->parse($line, $file)), $filters)) {
                    ++$matched;
                }
            }
        }

        return $matched;
    }

    /**
     * @param list<string> $files
     * @param array{level: string, levels: list<string>, search: string, match: string, time_window: string, audit_action: string, per_page: int, page: int} $filters
     *
     * @return list<array<string, mixed>>
     */
    private function readPage(string $source, array $files, array $filters): array
    {
        $entries = [];
        $matched = 0;
        $offset = ($filters['page'] - 1) * $filters['per_page'];
        $limit = $filters['per_page'];

        foreach ($files as $file) {
            foreach ($this->lineReader->readLines($file) as $line) {
                $entry = $this->entryPresenter->enrich($source, $this->lineParser->parse($line, $file));

                if (!$this->entryFilter->matches($entry, $filters)) {
                    continue;
                }

                ++$matched;

                if ($matched <= $offset) {
                    continue;
                }

                if (count($entries) >= $limit) {
                    return $entries;
                }

                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function entry(string $source, string $id): ?array
    {
        $source = $this->sourceRegistry->source($source);

        foreach ($this->sourceRegistry->files($this->logDir, $this->environment, $source) as $file) {
            foreach ($this->lineReader->readLines($file) as $line) {
                $entry = $this->entryPresenter->enrich($source, $this->lineParser->parse($line, $file));

                if ($entry['id'] !== $id) {
                    continue;
                }

                if (!$this->entryFilter->matchesTimeWindow($entry, '30d')) {
                    continue;
                }

                return $entry;
            }
        }

        return null;
    }
}
