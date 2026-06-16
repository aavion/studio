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
        if (in_array($source, ['access', 'audit'], true)) {
            $filters['level'] = '';
            $filters['levels'] = [];
        }
        $files = $this->sourceRegistry->files($this->logDir, $this->environment, $source);
        $matches = [];

        foreach ($files as $file) {
            foreach ($this->lineReader->readLines($file) as $line) {
                $entry = $this->entryPresenter->enrich($source, $this->lineParser->parse($line, $file));

                if (!$this->entryFilter->matches($entry, $filters)) {
                    continue;
                }

                $matches[] = $entry;
            }
        }

        $matched = count($matches);
        $pagination = $this->pagination->pagination($filters, $matched);
        $filters['page'] = $pagination['page'];
        $entries = array_slice(
            $matches,
            ($filters['page'] - 1) * $filters['per_page'],
            $filters['per_page'],
        );

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
