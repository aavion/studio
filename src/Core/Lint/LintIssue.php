<?php

declare(strict_types=1);

namespace App\Core\Lint;

use InvalidArgumentException;

final readonly class LintIssue
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        private string $code,
        private string $message,
        private ?int $line = null,
        private ?int $column = null,
        private array $details = [],
    ) {
        if ('' === trim($code)) {
            throw new InvalidArgumentException('Lint issue code must not be empty.');
        }

        if ('' === trim($message)) {
            throw new InvalidArgumentException('Lint issue message must not be empty.');
        }
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function create(string $code, string $message, ?int $line = null, ?int $column = null, array $details = []): self
    {
        return new self($code, $message, $line, $column, $details);
    }

    public function code(): string
    {
        return $this->code;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function line(): ?int
    {
        return $this->line;
    }

    public function column(): ?int
    {
        return $this->column;
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return array_filter([
            'line' => $this->line,
            'column' => $this->column,
            ...$this->details,
        ], static fn (mixed $value): bool => null !== $value);
    }
}
