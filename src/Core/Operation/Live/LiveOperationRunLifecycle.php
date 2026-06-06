<?php

declare(strict_types=1);

namespace App\Core\Operation\Live;

use App\Core\Message\CommonMessageCode;
use App\Core\Message\Message;
use App\Core\Operation\OperationMessageKey;
use App\Core\Workflow\WorkflowResult;

final readonly class LiveOperationRunLifecycle
{
    private const STATUS_QUEUED = 'queued';
    private const STATUS_RUNNING = 'running';
    private const STATUS_SUCCESS = 'success';
    private const STATUS_REQUIRES_REVIEW = 'requires_review';
    private const STATUS_FAILED = 'failed';
    private const TERMINAL_STATUSES = [self::STATUS_SUCCESS, self::STATUS_REQUIRES_REVIEW, self::STATUS_FAILED];

    public function __construct(
        private LiveOperationRunStorage $storage,
        private LiveOperationRunnerSupervisor $runnerSupervisor,
        private int $staleAfterSeconds = 3600,
    ) {
    }

    /**
     * @param callable(string, bool, array<string, mixed>): void $finish
     */
    public function markStaleIfNeeded(string $operationId, callable $finish): void
    {
        $state = $this->storage->read($operationId);

        if (null === $state || !$this->isStale($state)) {
            return;
        }

        $operation = (string) ($state['operation'] ?? 'unknown');
        $result = WorkflowResult::failed([
            Message::warning(
                CommonMessageCode::E_OPERATION_FAILED,
                OperationMessageKey::OPERATION_STALE,
                ['%operation%' => $operation],
                ['operation' => $operation, 'operation_id' => $operationId],
            ),
        ], ['operation' => $operation, 'operation_id' => $operationId]);

        $finish($operationId, false, $result->toArray());
    }

    /**
     * @return array{checked: int, removed: int}
     */
    public function cleanup(int $ttlSeconds = 3600): array
    {
        $directory = $this->storage->directory();

        if (!is_dir($directory)) {
            return ['checked' => 0, 'removed' => 0];
        }

        $checked = 0;
        $removed = 0;
        $cutoff = time() - max(0, $ttlSeconds);

        foreach (glob($directory.'/*.json') ?: [] as $path) {
            ++$checked;
            $operationId = basename($path, '.json');
            $state = $this->storage->read($operationId);

            if (null === $state || (!$this->isExpiredTerminal($state, $cutoff) && !$this->isExpiredActive($state, $cutoff))) {
                continue;
            }

            @unlink($path);
            @unlink($this->storage->outputPath($operationId));
            @unlink($this->storage->pidPath($operationId));
            ++$removed;
        }

        $this->runnerSupervisor->cleanupRunnerLock($ttlSeconds);

        return ['checked' => $checked, 'removed' => $removed];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function isStale(array $state): bool
    {
        $status = (string) ($state['status'] ?? '');

        if (!in_array($status, [self::STATUS_QUEUED, self::STATUS_RUNNING], true)) {
            return false;
        }

        $timestamp = $this->timestamp($state['updated_at'] ?? $state['created_at'] ?? null);

        return null !== $timestamp && $timestamp <= time() - max(1, $this->staleAfterSeconds);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function isExpiredTerminal(array $state, int $cutoff): bool
    {
        if (!in_array((string) ($state['status'] ?? ''), self::TERMINAL_STATUSES, true)) {
            return false;
        }

        $timestamp = $this->timestamp($state['finished_at'] ?? $state['updated_at'] ?? null);

        return null !== $timestamp && $timestamp <= $cutoff;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function isExpiredActive(array $state, int $cutoff): bool
    {
        if (!in_array((string) ($state['status'] ?? ''), [self::STATUS_QUEUED, self::STATUS_RUNNING], true)) {
            return false;
        }

        $timestamp = $this->timestamp($state['updated_at'] ?? $state['created_at'] ?? null);

        return null !== $timestamp && $timestamp <= $cutoff;
    }

    private function timestamp(mixed $value): ?int
    {
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        $timestamp = strtotime($value);

        return false === $timestamp ? null : $timestamp;
    }
}
