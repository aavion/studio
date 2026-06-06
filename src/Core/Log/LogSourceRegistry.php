<?php

declare(strict_types=1);

namespace App\Core\Log;

final readonly class LogSourceRegistry
{
    /**
     * @var array<string, array{label: string, pattern: string}>
     */
    private const SOURCES = [
        'application' => ['label' => 'admin.logs.sources.application', 'pattern' => '%env%.log'],
        'message' => ['label' => 'admin.logs.sources.message', 'pattern' => '%env%.system-message-*.log'],
        'audit' => ['label' => 'admin.logs.sources.audit', 'pattern' => '%env%.system-audit-*.log'],
        'access' => ['label' => 'admin.logs.sources.access', 'pattern' => '%env%.system-access-*.log'],
    ];

    /**
     * @return list<array{key: string, label: string}>
     */
    public function sourceOptions(): array
    {
        $options = [];

        foreach (self::SOURCES as $key => $source) {
            $options[] = ['key' => $key, 'label' => $source['label']];
        }

        return $options;
    }

    public function source(mixed $source): string
    {
        return is_string($source) && isset(self::SOURCES[$source]) ? $source : 'message';
    }

    /**
     * @return list<string>
     */
    public function files(string $logDir, string $environment, string $source): array
    {
        $pattern = $logDir.'/'.str_replace('%env%', $environment, self::SOURCES[$source]['pattern']);
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
}
