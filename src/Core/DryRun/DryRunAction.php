<?php

declare(strict_types=1);

namespace App\Core\DryRun;

use InvalidArgumentException;

final readonly class DryRunAction
{
    /**
     * @param list<string> $paths
     * @param list<DryRunDiff> $diffs
     * @param array<string, mixed> $context
     */
    public function __construct(
        private string $type,
        private string $label,
        private DryRunRisk $risk = DryRunRisk::Low,
        private array $paths = [],
        private array $diffs = [],
        private array $context = [],
    ) {
        if ('' === trim($type)) {
            throw new InvalidArgumentException('Dry-run action type must not be empty.');
        }

        if ('' === trim($label)) {
            throw new InvalidArgumentException('Dry-run action label must not be empty.');
        }

        foreach ($paths as $path) {
            if (!is_string($path) || '' === trim($path)) {
                throw new InvalidArgumentException('Dry-run action paths must contain non-empty strings.');
            }
        }

        foreach ($diffs as $diff) {
            if (!$diff instanceof DryRunDiff) {
                throw new InvalidArgumentException('Dry-run action diffs must contain only DryRunDiff instances.');
            }
        }
    }

    /**
     * @param list<string> $paths
     * @param list<DryRunDiff> $diffs
     * @param array<string, mixed> $context
     */
    public static function create(string $type, string $label, DryRunRisk $risk = DryRunRisk::Low, array $paths = [], array $diffs = [], array $context = []): self
    {
        return new self($type, $label, $risk, $paths, $diffs, $context);
    }

    public function type(): string
    {
        return $this->type;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function risk(): DryRunRisk
    {
        return $this->risk;
    }

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        return $this->paths;
    }

    /**
     * @return list<DryRunDiff>
     */
    public function diffs(): array
    {
        return $this->diffs;
    }

    public function hasDiffs(): bool
    {
        return [] !== $this->diffs;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * @return array{type: string, label: string, risk: string, paths: list<string>, diffs: list<array{type: string, label: string, payload: array<string, mixed>}>, context: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'label' => $this->label,
            'risk' => $this->risk->label(),
            'paths' => $this->paths,
            'diffs' => array_map(static fn (DryRunDiff $diff): array => $diff->toArray(), $this->diffs),
            'context' => $this->context,
        ];
    }
}
