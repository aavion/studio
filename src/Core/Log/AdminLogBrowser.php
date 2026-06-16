<?php

declare(strict_types=1);

namespace App\Core\Log;

final readonly class AdminLogBrowser
{
    private const APPLICATION_SOURCE = ['application' => ['label' => 'admin.logs.sources.application']];

    public function __construct(
        private DatabaseLogBrowser $databaseBrowser,
        private LogFileBrowser $fileBrowser,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    public function browse(array $query): array
    {
        $source = $query['source'] ?? null;
        if ('application' === $source) {
            $view = $this->fileBrowser->browse([...$query, 'source' => 'application']);
            $view['sources'] = $this->sourceOptions();
            $view['capabilities'] = $this->capabilities('application');

            return $view;
        }

        $view = $this->databaseBrowser->browse($query);
        $view['sources'] = $this->sourceOptions();

        return $view;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function entry(string $source, string $id): ?array
    {
        return 'application' === $source
            ? $this->fileBrowser->entry($source, $id)
            : $this->databaseBrowser->entry($source, $id);
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public function sourceOptions(): array
    {
        return [
            ['key' => 'application', 'label' => self::APPLICATION_SOURCE['application']['label']],
            ...$this->databaseBrowser->sourceOptions(),
        ];
    }

    /**
     * @return array{level_filter: bool, audit_action_filter: bool, signal_reason_filter: bool}
     */
    private function capabilities(string $source): array
    {
        return [
            'level_filter' => 'application' === $source,
            'audit_action_filter' => false,
            'signal_reason_filter' => false,
        ];
    }
}
