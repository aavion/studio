<?php

declare(strict_types=1);

namespace App\Core\Operation\Live;

use App\Core\ActionLog\ActionLogEntry;
use App\Core\Log\OperationLoggerInterface;
use Throwable;

final readonly class LiveOperationRunStore
{
    private const STATUS_REQUIRES_REVIEW = 'requires_review';
    private LiveOperationRunStorage $storage;
    private LiveOperationRunCreator $creator;
    private LiveOperationRunnerSupervisor $runnerSupervisor;
    private LiveOperationRunProgressWriter $progressWriter;
    private LiveOperationRunLifecycle $lifecycle;

    public function __construct(
        private string $projectDir,
        private string $environment,
        private int $staleAfterSeconds = 3600,
        private ?OperationLoggerInterface $operationLogger = null,
        private LiveOperationRunPresenter $presenter = new LiveOperationRunPresenter(),
        ?LiveOperationRunStorage $storage = null,
        ?LiveOperationRunCreator $creator = null,
        ?LiveOperationRunnerSupervisor $runnerSupervisor = null,
        ?LiveOperationRunProgressWriter $progressWriter = null,
        ?LiveOperationRunLifecycle $lifecycle = null,
    ) {
        $this->storage = $storage ?? new LiveOperationRunStorage($this->projectDir, $this->environment);
        $this->creator = $creator ?? new LiveOperationRunCreator($this->storage);
        $this->runnerSupervisor = $runnerSupervisor ?? new LiveOperationRunnerSupervisor($this->storage, $this->environment);
        $this->progressWriter = $progressWriter ?? new LiveOperationRunProgressWriter($this->storage);
        $this->lifecycle = $lifecycle ?? new LiveOperationRunLifecycle($this->storage, $this->runnerSupervisor, $this->staleAfterSeconds);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{operation_id: string, token: string, operation: string, label: string, status: string}
     */
    public function create(string $operation, array $payload, string $label): array
    {
        return $this->creator->create($operation, $payload, $label);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function read(string $operationId): ?array
    {
        return $this->storage->read($operationId);
    }

    public function tokenMatches(string $operationId, string $token): bool
    {
        $state = $this->read($operationId);

        return is_array($state) && hash_equals((string) ($state['token'] ?? ''), $token);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function summaries(): array
    {
        $directory = $this->storage->directory();

        if (!is_dir($directory)) {
            return [];
        }

        $summaries = [];

        foreach (glob($directory.'/*.json') ?: [] as $path) {
            $operationId = basename($path, '.json');

            if (!$this->storage->validOperationId($operationId)) {
                continue;
            }

            $this->markStaleIfNeeded($operationId);
            $state = $this->read($operationId);

            if (null === $state) {
                continue;
            }

            $summaries[] = $this->presenter->summary($state);
        }

        usort(
            $summaries,
            static fn (array $left, array $right): int => strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? '')),
        );

        return $summaries;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function report(string $operationId): ?array
    {
        if (!$this->storage->validOperationId($operationId)) {
            return null;
        }

        $this->markStaleIfNeeded($operationId);
        $state = $this->read($operationId);

        if (null === $state) {
            return null;
        }

        return $this->presenter->report($state);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function readForRunner(string $operationId, string $token): ?array
    {
        return $this->tokenMatches($operationId, $token) ? $this->read($operationId) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function claimForRunner(string $operationId, string $token): ?array
    {
        return $this->storage->claimQueued($operationId, $token);
    }

    public function markRunning(string $operationId, int $total = 0): void
    {
        $this->progressWriter->markRunning($operationId, $total);
    }

    public function acquireRunnerLock(string $operationId, int $ttlSeconds = 3600): ?LiveOperationRunLock
    {
        return $this->runnerSupervisor->acquireRunnerLock($this, $operationId, $ttlSeconds);
    }

    public function touchRunnerLock(string $owner): void
    {
        $this->runnerSupervisor->touchRunnerLock($owner);
    }

    public function releaseRunnerLock(string $owner): void
    {
        $this->runnerSupervisor->releaseRunnerLock($owner);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function runnerLockStatus(int $ttlSeconds = 3600): ?array
    {
        return $this->runnerSupervisor->runnerLockStatus($ttlSeconds);
    }

    /**
     * @return array{killed: bool, lock_cleared: bool, pid: int|null, reason: string}
     */
    public function killStaleRunner(int $ttlSeconds = 3600): array
    {
        return $this->runnerSupervisor->killStaleRunner($ttlSeconds);
    }

    public function clearRunnerLock(bool $staleOnly = true, int $ttlSeconds = 3600): bool
    {
        return $this->runnerSupervisor->clearRunnerLock($staleOnly, $ttlSeconds);
    }

    public function setTotal(string $operationId, int $total): void
    {
        $this->progressWriter->setTotal($operationId, $total);
    }

    public function appendEntry(string $operationId, ActionLogEntry $entry, int $index, int $total): void
    {
        $this->progressWriter->appendEntry($operationId, $entry, $index, $total);
    }

    /**
     * @param array<string, mixed> $result
     */
    public function finish(string $operationId, bool $success, array $result): void
    {
        $this->progressWriter->finish($operationId, $success, $result);
        $this->logFinished($operationId);
    }

    /**
     * @return array{checked: int, removed: int}
     */
    public function cleanup(int $ttlSeconds = 3600): array
    {
        return $this->lifecycle->cleanup($ttlSeconds);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function pollingPayload(string $operationId, string $token, int $cursor = 0): ?array
    {
        if (!$this->tokenMatches($operationId, $token)) {
            return null;
        }

        $this->markStaleIfNeeded($operationId);
        $state = $this->read($operationId);

        if (null === $state) {
            return null;
        }

        return $this->presenter->pollingPayload($state, $cursor);
    }

    /**
     * @return array{operation: string, payload: array<string, mixed>, label: string}|null
     */
    public function continuation(string $operationId, string $token): ?array
    {
        if (!$this->tokenMatches($operationId, $token)) {
            return null;
        }

        $state = $this->read($operationId);

        if (null === $state || self::STATUS_REQUIRES_REVIEW !== (string) ($state['status'] ?? '')) {
            return null;
        }

        return $this->presenter->continuationFromResult($state['result'] ?? null);
    }

    /**
     * @return array{operation: string, payload: array<string, mixed>, label: string}|null
     */
    public function continuationForOperator(string $operationId): ?array
    {
        if (!$this->storage->validOperationId($operationId)) {
            return null;
        }

        $state = $this->read($operationId);

        if (null === $state || self::STATUS_REQUIRES_REVIEW !== (string) ($state['status'] ?? '')) {
            return null;
        }

        return $this->presenter->continuationFromResult($state['result'] ?? null);
    }

    private function markStaleIfNeeded(string $operationId): void
    {
        $this->lifecycle->markStaleIfNeeded($operationId, $this->finish(...));
    }

    private function logFinished(string $operationId): void
    {
        if (null === $this->operationLogger) {
            return;
        }

        $state = $this->read($operationId);

        if (null === $state) {
            return;
        }

        try {
            $this->operationLogger->logFinished($state);
        } catch (Throwable) {
            return;
        }
    }

    public function outputPath(string $operationId): string
    {
        return $this->storage->outputPath($operationId);
    }

    public function pidPath(string $operationId): string
    {
        return $this->storage->pidPath($operationId);
    }

}
