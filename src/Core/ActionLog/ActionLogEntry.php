<?php

declare(strict_types=1);

namespace App\Core\ActionLog;

use App\Core\Message\Message;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ActionLogEntry
{
    /**
     * @param list<Message> $issues
     * @param list<Message> $messages
     * @param array<string, mixed> $context
     */
    public function __construct(
        private string $name,
        private ActionLogStatus $status,
        private ?DateTimeImmutable $startedAt = null,
        private ?DateTimeImmutable $finishedAt = null,
        private array $issues = [],
        private array $messages = [],
        private array $context = [],
    ) {
        if ('' === trim($name)) {
            throw new InvalidArgumentException('Action log entry name must not be empty.');
        }

        foreach ($issues as $issue) {
            if (!$issue instanceof Message) {
                throw new InvalidArgumentException('Action log entry issues must contain only Message instances.');
            }
        }

        foreach ($messages as $message) {
            if (!$message instanceof Message) {
                throw new InvalidArgumentException('Action log entry messages must contain only Message instances.');
            }
        }

        if (null !== $startedAt && null !== $finishedAt && $finishedAt < $startedAt) {
            throw new InvalidArgumentException('Action log entry finish time must not be before start time.');
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function pending(string $name, array $context = []): self
    {
        return new self($name, ActionLogStatus::Pending, context: $context);
    }

    public function start(?DateTimeImmutable $now = null): self
    {
        return new self($this->name, ActionLogStatus::Running, $now ?? new DateTimeImmutable(), context: $this->context);
    }

    /**
     * @param list<Message> $issues
     * @param array<string, mixed> $context
     * @param list<Message> $messages
     */
    public function finish(ActionLogStatus $status, array $issues = [], array $context = [], ?DateTimeImmutable $now = null, array $messages = []): self
    {
        if (!$status->isTerminal()) {
            throw new InvalidArgumentException('Action log entry can only finish with a terminal status.');
        }

        return new self(
            $this->name,
            $status,
            $this->startedAt,
            $now ?? new DateTimeImmutable(),
            $issues,
            $messages,
            [...$this->context, ...$context],
        );
    }

    public function name(): string
    {
        return $this->name;
    }

    public function status(): ActionLogStatus
    {
        return $this->status;
    }

    public function startedAt(): ?DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function finishedAt(): ?DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function durationMilliseconds(): ?int
    {
        if (null === $this->startedAt || null === $this->finishedAt) {
            return null;
        }

        $seconds = (int) $this->finishedAt->format('U') - (int) $this->startedAt->format('U');
        $microseconds = (int) $this->finishedAt->format('u') - (int) $this->startedAt->format('u');

        return (int) (($seconds * 1000) + floor($microseconds / 1000));
    }

    /**
     * @return list<Message>
     */
    public function issues(): array
    {
        return $this->issues;
    }

    /**
     * @return list<Message>
     */
    public function messages(): array
    {
        return $this->messages;
    }

    public function hasIssues(): bool
    {
        return [] !== $this->issues;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * @return array{name: string, status: string, started_at: string|null, finished_at: string|null, duration_ms: int|null, issues: list<array<string, mixed>>, messages: list<array<string, mixed>>, context: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'status' => $this->status->value,
            'started_at' => $this->startedAt?->format(DATE_ATOM),
            'finished_at' => $this->finishedAt?->format(DATE_ATOM),
            'duration_ms' => $this->durationMilliseconds(),
            'issues' => array_map(static fn (Message $issue): array => $issue->toArray(), $this->issues),
            'messages' => array_map(static fn (Message $message): array => $message->toArray(), $this->messages),
            'context' => $this->context,
        ];
    }
}
