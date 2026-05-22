<?php

declare(strict_types=1);

namespace App\Core\Diff;

use InvalidArgumentException;

final readonly class StructuredDiff
{
    /**
     * @param list<StructuredDiffChange> $changes
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private StructuredDiffType $type,
        private string $label,
        private array $changes = [],
        private array $payload = [],
    ) {
        if ('' === trim($label)) {
            throw new InvalidArgumentException('Structured diff label must not be empty.');
        }

        foreach ($changes as $change) {
            if (!$change instanceof StructuredDiffChange) {
                throw new InvalidArgumentException('Structured diffs may only contain StructuredDiffChange instances.');
            }
        }
    }

    public function type(): StructuredDiffType
    {
        return $this->type;
    }

    public function label(): string
    {
        return $this->label;
    }

    /**
     * @return list<StructuredDiffChange>
     */
    public function changes(): array
    {
        return $this->changes;
    }

    public function hasChanges(): bool
    {
        return [] !== $this->changes;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            ...$this->payload,
            'changes' => array_map(static fn (StructuredDiffChange $change): array => $change->toArray(), $this->changes),
        ];
    }
}
