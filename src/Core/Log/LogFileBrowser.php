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
        $files = $this->sourceRegistry->files($this->logDir, $this->environment, $source);
        $entries = [];
        $matched = 0;
        $offset = 'all' === $filters['per_page'] ? 0 : ($filters['page'] - 1) * (int) $filters['per_page'];
        $limit = 'all' === $filters['per_page'] ? PHP_INT_MAX : (int) $filters['per_page'];

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

                if (count($entries) < $limit) {
                    $entries[] = $entry;
                }
            }
        }

        return [
            'sources' => $this->sourceRegistry->sourceOptions(),
            'selected_source' => $source,
            'filters' => $filters,
            'entries' => $entries,
            'files' => array_map('basename', $files),
            'pagination' => $this->pagination->pagination($filters, $matched),
            'per_page_options' => $this->pagination->perPageOptions(),
            'time_window_options' => $this->pagination->timeWindowOptions(),
            'match_options' => $this->pagination->matchOptions(),
        ];
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
