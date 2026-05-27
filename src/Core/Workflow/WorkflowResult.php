<?php

declare(strict_types=1);

namespace App\Core\Workflow;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use InvalidArgumentException;

/**
 * @template TValue
 */
final readonly class WorkflowResult
{
    /**
     * @param TValue|null $value
     * @param list<Message> $issues
     * @param list<Message> $messages
     * @param array<string, mixed> $context
     */
    private function __construct(
        private WorkflowStatus $status,
        private mixed $value = null,
        private array $issues = [],
        private array $context = [],
        private array $messages = [],
    ) {
        foreach ($issues as $issue) {
            if (!$issue instanceof Message) {
                throw new InvalidArgumentException('Workflow result issues must contain only Message instances.');
            }
        }

        foreach ($messages as $message) {
            if (!$message instanceof Message) {
                throw new InvalidArgumentException('Workflow result messages must contain only Message instances.');
            }
        }

        if ($status->requiresIssue() && [] === $issues) {
            throw new InvalidArgumentException(sprintf('Workflow result status "%s" requires at least one issue.', $status->value));
        }

        if (WorkflowStatus::RequiresReview === $status && !$this->hasReviewPrompt($issues)) {
            throw new InvalidArgumentException('Workflow result status "requires_review" requires a user-facing confirmation prompt issue.');
        }
    }

    /**
     * @template TSuccessValue
     *
     * @param TSuccessValue|null $value
     * @param array<string, mixed> $context
     * @param list<Message> $messages
     *
     * @return self<TSuccessValue>
     */
    public static function success(mixed $value = null, array $context = [], array $messages = []): self
    {
        return new self(WorkflowStatus::Success, $value, [], $context, $messages);
    }

    /**
     * @param list<Message> $issues
     * @param array<string, mixed> $context
     * @param list<Message> $messages
     *
     * @return self<null>
     */
    public static function invalid(array $issues, array $context = [], array $messages = []): self
    {
        return new self(WorkflowStatus::Invalid, null, $issues, $context, $messages);
    }

    /**
     * @template TReviewValue
     *
     * @param TReviewValue|null $value
     * @param list<Message> $issues
     * @param array<string, mixed> $context
     * @param list<Message> $messages
     *
     * @return self<TReviewValue>
     */
    public static function requiresReview(mixed $value, array $issues, array $context = [], array $messages = []): self
    {
        return new self(WorkflowStatus::RequiresReview, $value, $issues, $context, $messages);
    }

    /**
     * @param list<Message> $issues
     * @param array<string, mixed> $context
     * @param list<Message> $messages
     *
     * @return self<null>
     */
    public static function blocked(array $issues, array $context = [], array $messages = []): self
    {
        return new self(WorkflowStatus::Blocked, null, $issues, $context, $messages);
    }

    /**
     * @param list<Message> $issues
     * @param array<string, mixed> $context
     * @param list<Message> $messages
     *
     * @return self<null>
     */
    public static function failed(array $issues, array $context = [], array $messages = []): self
    {
        return new self(WorkflowStatus::Failed, null, $issues, $context, $messages);
    }

    public function status(): WorkflowStatus
    {
        return $this->status;
    }

    public function isSuccess(): bool
    {
        return WorkflowStatus::Success === $this->status;
    }

    public function isRecoverable(): bool
    {
        return $this->status->isRecoverable();
    }

    /**
     * @return TValue|null
     */
    public function value(): mixed
    {
        return $this->value;
    }

    /**
     * @return list<Message>
     */
    public function issues(): array
    {
        return $this->issues;
    }

    public function hasIssues(): bool
    {
        return [] !== $this->issues;
    }

    public function firstIssue(): ?Message
    {
        return $this->issues[0] ?? null;
    }

    /**
     * @return list<Message>
     */
    public function messages(): array
    {
        return $this->messages;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * @return array{status: string, success: bool, recoverable: bool, value: TValue|null, issues: list<array<string, mixed>>, messages: list<array<string, mixed>>, context: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'success' => $this->isSuccess(),
            'recoverable' => $this->isRecoverable(),
            'value' => $this->value,
            'issues' => array_map(static fn (Message $issue): array => $issue->toArray(), $this->issues),
            'messages' => array_map(static fn (Message $message): array => $message->toArray(), $this->messages),
            'context' => $this->context,
        ];
    }

    /**
     * @param list<Message> $issues
     */
    private function hasReviewPrompt(array $issues): bool
    {
        foreach ($issues as $issue) {
            if (in_array($issue->level(), [MessageLevel::Info, MessageLevel::Warning], true)) {
                return true;
            }
        }

        return false;
    }
}
