<?php

declare(strict_types=1);

namespace App\Core\Log;

final readonly class LogEntryPresenter
{
    /**
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>
     */
    public function enrich(string $source, array $entry): array
    {
        $entry['id'] = $this->entryId($source, $entry);
        $entry['source'] = $source;
        $entry['summary'] = $this->summary($source, $entry);

        return $entry;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function entryId(string $source, array $entry): string
    {
        return substr(hash('sha256', $source."\0".($entry['file'] ?? '')."\0".($entry['raw'] ?? '')), 0, 24);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function summary(string $source, array $entry): string
    {
        $context = is_array($entry['context'] ?? null) ? $entry['context'] : [];

        return match ($source) {
            'access' => trim(($context['method'] ?? 'n/a').' '.($context['requested_path'] ?? $context['path'] ?? 'n/a')),
            'audit' => (string) ($context['action'] ?? $entry['message'] ?? 'n/a'),
            default => (string) ($entry['message'] ?? 'n/a'),
        };
    }
}
