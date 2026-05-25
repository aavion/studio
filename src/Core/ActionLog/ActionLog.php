<?php

declare(strict_types=1);

namespace App\Core\ActionLog;

use InvalidArgumentException;

final readonly class ActionLog
{
    /**
     * @param list<ActionLogEntry> $entries
     */
    public function __construct(
        private array $entries = [],
    ) {
        foreach ($entries as $entry) {
            if (!$entry instanceof ActionLogEntry) {
                throw new InvalidArgumentException('Action log entries must contain only ActionLogEntry instances.');
            }
        }
    }

    public static function create(): self
    {
        return new self();
    }

    public function add(ActionLogEntry $entry): self
    {
        return new self([...$this->entries, $entry]);
    }

    /**
     * @return list<ActionLogEntry>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    public function hasFailures(): bool
    {
        foreach ($this->entries as $entry) {
            if (ActionLogStatus::Failed === $entry->status()) {
                return true;
            }
        }

        return false;
    }

    public function hasWarnings(): bool
    {
        foreach ($this->entries as $entry) {
            if (ActionLogStatus::Warning === $entry->status()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, int>
     */
    public function statusCounts(): array
    {
        $counts = [];

        foreach ($this->entries as $entry) {
            $status = $entry->status()->value;
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @return array{entries: list<array<string, mixed>>, status_counts: array<string, int>, has_failures: bool, has_warnings: bool}
     */
    public function toArray(): array
    {
        return [
            'entries' => array_map(static fn (ActionLogEntry $entry): array => $entry->toArray(), $this->entries),
            'status_counts' => $this->statusCounts(),
            'has_failures' => $this->hasFailures(),
            'has_warnings' => $this->hasWarnings(),
        ];
    }
}
