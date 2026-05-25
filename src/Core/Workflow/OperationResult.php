<?php

declare(strict_types=1);

namespace App\Core\Workflow;

use App\Core\Message\Message;
use InvalidArgumentException;

/**
 * @template TValue
 */
final readonly class OperationResult
{
    /**
     * @param TValue|null $value
     * @param list<OperationIssue> $issues
     * @param list<Message> $messages
     * @param array<string, mixed> $context
     */
    private function __construct(
        private OperationStatus $status,
        private mixed $value = null,
        private array $issues = [],
        private array $context = [],
        private array $messages = [],
    ) {
        foreach ($issues as $issue) {
            if (!$issue instanceof OperationIssue) {
                throw new InvalidArgumentException('Operation result issues must contain only OperationIssue instances.');
            }
        }

        foreach ($messages as $message) {
            if (!$message instanceof Message) {
                throw new InvalidArgumentException('Operation result messages must contain only Message instances.');
            }
        }

        if ($status->requiresIssue() && [] === $issues) {
            throw new InvalidArgumentException(sprintf('Operation result status "%s" requires at least one issue.', $status->value));
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
        return new self(OperationStatus::Success, $value, [], $context, $messages);
    }

    /**
     * @param list<OperationIssue> $issues
     * @param array<string, mixed> $context
     * @param list<Message> $messages
     *
     * @return self<null>
     */
    public static function invalid(array $issues, array $context = [], array $messages = []): self
    {
        return new self(OperationStatus::Invalid, null, $issues, $context, $messages);
    }

    /**
     * @template TReviewValue
     *
     * @param TReviewValue|null $value
     * @param list<OperationIssue> $issues
     * @param array<string, mixed> $context
     * @param list<Message> $messages
     *
     * @return self<TReviewValue>
     */
    public static function requiresReview(mixed $value, array $issues, array $context = [], array $messages = []): self
    {
        return new self(OperationStatus::RequiresReview, $value, $issues, $context, $messages);
    }

    /**
     * @param list<OperationIssue> $issues
     * @param array<string, mixed> $context
     * @param list<Message> $messages
     *
     * @return self<null>
     */
    public static function blocked(array $issues, array $context = [], array $messages = []): self
    {
        return new self(OperationStatus::Blocked, null, $issues, $context, $messages);
    }

    /**
     * @param list<OperationIssue> $issues
     * @param array<string, mixed> $context
     * @param list<Message> $messages
     *
     * @return self<null>
     */
    public static function failed(array $issues, array $context = [], array $messages = []): self
    {
        return new self(OperationStatus::Failed, null, $issues, $context, $messages);
    }

    public function status(): OperationStatus
    {
        return $this->status;
    }

    public function isSuccess(): bool
    {
        return OperationStatus::Success === $this->status;
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
     * @return list<OperationIssue>
     */
    public function issues(): array
    {
        return $this->issues;
    }

    public function hasIssues(): bool
    {
        return [] !== $this->issues;
    }

    public function firstIssue(): ?OperationIssue
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
            'issues' => array_map(static fn (OperationIssue $issue): array => $issue->toArray(), $this->issues),
            'messages' => array_map(static fn (Message $message): array => $message->toArray(), $this->messages),
            'context' => $this->context,
        ];
    }
}
