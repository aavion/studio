<?php

declare(strict_types=1);

namespace App\Scheduler;

use InvalidArgumentException;

final readonly class SchedulerTaskDefinition
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private string $identifier,
        private string $labelKey,
        private string $descriptionKey,
        private string $source,
        private SchedulerTaskType $type,
        private string $target,
        private string $defaultCronExpression,
        private bool $trusted = false,
        private array $metadata = [],
    ) {
        $this->assertToken($identifier, 'Scheduler task identifier');
        $this->assertToken($source, 'Scheduler task source');

        if ('' === trim($labelKey) || '' === trim($descriptionKey)) {
            throw new InvalidArgumentException('Scheduler task translation keys must not be empty.');
        }

        if ('' === trim($target)) {
            throw new InvalidArgumentException('Scheduler task target must not be empty.');
        }

        if ('' === trim($defaultCronExpression)) {
            throw new InvalidArgumentException('Scheduler task cron expression must not be empty.');
        }
    }

    public static function command(
        string $identifier,
        string $labelKey,
        string $descriptionKey,
        string $command,
        string $defaultCronExpression,
        string $source = 'system',
        bool $trusted = true,
    ): self {
        return new self($identifier, $labelKey, $descriptionKey, $source, SchedulerTaskType::Command, $command, $defaultCronExpression, $trusted);
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function labelKey(): string
    {
        return $this->labelKey;
    }

    public function descriptionKey(): string
    {
        return $this->descriptionKey;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function type(): SchedulerTaskType
    {
        return $this->type;
    }

    public function target(): string
    {
        return $this->target;
    }

    public function defaultCronExpression(): string
    {
        return $this->defaultCronExpression;
    }

    public function trusted(): bool
    {
        return $this->trusted;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    private function assertToken(string $value, string $label): void
    {
        if (1 !== preg_match('/^[a-z0-9][a-z0-9_.:-]{2,159}$/', $value)) {
            throw new InvalidArgumentException(sprintf('%s "%s" is invalid.', $label, $value));
        }
    }
}
