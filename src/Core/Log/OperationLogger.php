<?php

declare(strict_types=1);

namespace App\Core\Log;

use App\Core\Message\CommonMessageCode;
use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Message\MessageReporterInterface;
use App\Core\Operation\OperationMessageCode;
use App\Core\Operation\OperationMessageKey;

final readonly class OperationLogger implements OperationLoggerInterface
{
    public function __construct(private MessageReporterInterface $messageReporter)
    {
    }

    /**
     * @param array<string, mixed> $state
     */
    public function logFinished(array $state): void
    {
        $status = $this->stringValue($state['status'] ?? null, 'unknown');
        $result = is_array($state['result'] ?? null) ? $state['result'] : [];
        $context = [
            'operation_id' => $this->stringValue($state['operation_id'] ?? null, ''),
            'operation' => $this->stringValue($state['operation'] ?? null, 'unknown'),
            'label' => $this->stringValue($state['label'] ?? null, ''),
            'status' => $status,
            'result_status' => $this->stringValue($result['status'] ?? null, ''),
            'created_at' => $this->nullableString($state['created_at'] ?? null),
            'started_at' => $this->nullableString($state['started_at'] ?? null),
            'finished_at' => $this->nullableString($state['finished_at'] ?? null),
            'duration_ms' => $this->durationMilliseconds($state),
            'entry_count' => is_array($state['entries'] ?? null) ? count($state['entries']) : 0,
            'issue_count' => is_array($result['issues'] ?? null) ? count($result['issues']) : 0,
            'message_count' => is_array($result['messages'] ?? null) ? count($result['messages']) : 0,
            'can_continue' => $this->canContinue($result),
        ];

        $parameters = ['%operation%' => $context['operation']];
        $message = match ($status) {
            'success' => Message::info(CommonMessageCode::SUCCESS, OperationMessageKey::OPERATION_FINISHED, $parameters, $context),
            'requires_review' => Message::create(OperationMessageCode::OPERATION_ACTION_REQUIRED, OperationMessageKey::OPERATION_REQUIRES_REVIEW, $parameters, $context, MessageLevel::Warning),
            'failed' => Message::error(CommonMessageCode::E_OPERATION_FAILED, OperationMessageKey::OPERATION_FAILED, $parameters, $context),
            default => Message::warning(CommonMessageCode::E_OPERATION_FAILED, OperationMessageKey::OPERATION_FINISHED_UNKNOWN, $parameters, $context),
        };

        $this->messageReporter->report($message, [
            'operation' => 'live_operation.summary',
        ]);
    }

    private function stringValue(mixed $value, string $fallback): string
    {
        return is_string($value) && '' !== trim($value) ? $value : $fallback;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && '' !== trim($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function durationMilliseconds(array $state): ?int
    {
        $startedAt = $this->timestamp($state['started_at'] ?? null);
        $finishedAt = $this->timestamp($state['finished_at'] ?? null);

        return null === $startedAt || null === $finishedAt
            ? null
            : max(0, (int) round(($finishedAt - $startedAt) * 1000));
    }

    private function timestamp(mixed $value): ?float
    {
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        try {
            $date = \DateTimeImmutable::createFromFormat(DATE_ATOM, $value) ?: new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }

        return (float) $date->format('U.u');
    }

    /**
     * @param array<string, mixed> $result
     */
    private function canContinue(array $result): bool
    {
        $context = is_array($result['context'] ?? null) ? $result['context'] : [];

        return is_array($context['live_operation_continuation'] ?? null);
    }
}
