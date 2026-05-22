<?php

declare(strict_types=1);

namespace App\Core\Workflow;

use InvalidArgumentException;

/**
 * @template TValue
 */
final readonly class OperationResult
{
    /**
     * @param TValue|null $value
     * @param list<OperationIssue> $issues
     * @param array<string, mixed> $context
     */
    private function __construct(
        private OperationStatus $status,
        private mixed $value = null,
        private array $issues = [],
        private array $context = [],
    ) {
        foreach ($issues as $issue) {
            if (!$issue instanceof OperationIssue) {
                throw new InvalidArgumentException('Operation result issues must contain only OperationIssue instances.');
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
     *
     * @return self<TSuccessValue>
     */
    public static function success(mixed $value = null, array $context = []): self
    {
        return new self(OperationStatus::Success, $value, [], $context);
    }

    /**
     * @param list<OperationIssue> $issues
     * @param array<string, mixed> $context
     *
     * @return self<null>
     */
    public static function invalid(array $issues, array $context = []): self
    {
        return new self(OperationStatus::Invalid, null, $issues, $context);
    }

    /**
     * @template TReviewValue
     *
     * @param TReviewValue|null $value
     * @param list<OperationIssue> $issues
     * @param array<string, mixed> $context
     *
     * @return self<TReviewValue>
     */
    public static function requiresReview(mixed $value, array $issues, array $context = []): self
    {
        return new self(OperationStatus::RequiresReview, $value, $issues, $context);
    }

    /**
     * @param list<OperationIssue> $issues
     * @param array<string, mixed> $context
     *
     * @return self<null>
     */
    public static function blocked(array $issues, array $context = []): self
    {
        return new self(OperationStatus::Blocked, null, $issues, $context);
    }

    /**
     * @param list<OperationIssue> $issues
     * @param array<string, mixed> $context
     *
     * @return self<null>
     */
    public static function failed(array $issues, array $context = []): self
    {
        return new self(OperationStatus::Failed, null, $issues, $context);
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
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * @return array{status: string, success: bool, recoverable: bool, value: TValue|null, issues: list<array{code: string, message: string, context: array<string, mixed>}>, context: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'success' => $this->isSuccess(),
            'recoverable' => $this->isRecoverable(),
            'value' => $this->value,
            'issues' => array_map(static fn (OperationIssue $issue): array => $issue->toArray(), $this->issues),
            'context' => $this->context,
        ];
    }
}
