<?php

declare(strict_types=1);

namespace App\Core\Diff;

use InvalidArgumentException;

final readonly class StructuredDiffChange
{
    public function __construct(
        private string $path,
        private StructuredDiffChangeType $type,
        private mixed $before = null,
        private mixed $after = null,
    ) {
        if ('' === trim($path)) {
            throw new InvalidArgumentException('Structured diff change path must not be empty.');
        }
    }

    public static function added(string $path, mixed $after): self
    {
        return new self($path, StructuredDiffChangeType::Added, after: $after);
    }

    public static function removed(string $path, mixed $before): self
    {
        return new self($path, StructuredDiffChangeType::Removed, before: $before);
    }

    public static function changed(string $path, mixed $before, mixed $after): self
    {
        return new self($path, StructuredDiffChangeType::Changed, $before, $after);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function type(): StructuredDiffChangeType
    {
        return $this->type;
    }

    public function before(): mixed
    {
        return $this->before;
    }

    public function after(): mixed
    {
        return $this->after;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'path' => $this->path,
            'type' => $this->type->value,
            'before' => $this->before,
            'after' => $this->after,
        ], static fn (mixed $value): bool => null !== $value);
    }
}
