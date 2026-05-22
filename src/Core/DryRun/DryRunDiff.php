<?php

declare(strict_types=1);

namespace App\Core\DryRun;

use App\Core\Diff\KeyValueDiffGenerator;
use App\Core\Diff\StructuredDiff;
use App\Core\Diff\TextDiffGenerator;
use InvalidArgumentException;

final readonly class DryRunDiff
{
    /**
     * @param array<string, mixed> $payload
     */
    private function __construct(
        private DryRunDiffType $type,
        private string $label,
        private array $payload,
    ) {
        if ('' === trim($label)) {
            throw new InvalidArgumentException('Dry-run diff label must not be empty.');
        }
    }

    public static function text(string $label, string $before, string $after): self
    {
        return self::fromStructuredDiff((new TextDiffGenerator())->diff($label, $before, $after));
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public static function keyValue(string $label, array $before, array $after): self
    {
        return self::fromStructuredDiff((new KeyValueDiffGenerator())->diff($label, $before, $after));
    }

    public static function fromStructuredDiff(StructuredDiff $diff): self
    {
        return new self(
            DryRunDiffType::from($diff->type()->value),
            $diff->label(),
            $diff->toPayload(),
        );
    }

    public function type(): DryRunDiffType
    {
        return $this->type;
    }

    public function label(): string
    {
        return $this->label;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    /**
     * @return array{type: string, label: string, payload: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'label' => $this->label,
            'payload' => $this->payload,
        ];
    }
}
