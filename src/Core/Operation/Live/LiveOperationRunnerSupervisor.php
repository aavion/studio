<?php

declare(strict_types=1);

namespace App\Core\Operation\Live;

use RuntimeException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Throwable;

final readonly class LiveOperationRunnerSupervisor
{
    private const RUNNER_LOCK_DIRECTORY = 'runner.lock';
    private const RUNNER_LOCK_STATE = 'state.json';
    private LiveOperationRunnerProcessInspector $processInspector;
    private LockFactory $runnerLockFactory;

    public function __construct(
        private LiveOperationRunStorage $storage,
        private string $environment,
        ?LiveOperationRunnerProcessInspector $processInspector = null,
        ?LockFactory $runnerLockFactory = null,
    ) {
        $this->processInspector = $processInspector ?? new LiveOperationRunnerProcessInspector($this->storage);
        $this->runnerLockFactory = $runnerLockFactory ?? new LockFactory(new FlockStore($this->runnerLockDirectory()));
    }

    public function acquireRunnerLock(LiveOperationRunStore $store, string $operationId, int $ttlSeconds = 3600): ?LiveOperationRunLock
    {
        if (!$this->storage->validOperationId($operationId)) {
            return null;
        }

        $owner = bin2hex(random_bytes(16));
        $lock = $this->runnerLockFactory->createLock($this->runnerLockName(), max(1, $ttlSeconds), autoRelease: false);

        if (!$lock->acquire(false)) {
            if ($this->isRunnerLockExpired($ttlSeconds)) {
                $this->clearRunnerLock(staleOnly: true, ttlSeconds: $ttlSeconds);
            }

            return null;
        }

        try {
            $this->writeRunnerLockState($owner, $operationId);

            return new LiveOperationRunLock($store, $owner, $lock, $ttlSeconds);
        } catch (Throwable) {
            $lock->release();
        }

        return null;
    }

    public function touchRunnerLock(string $owner): void
    {
        $state = $this->readRunnerLockState();

        if (!is_array($state) || !hash_equals((string) ($state['owner'] ?? ''), $owner)) {
            return;
        }

        $this->writeRunnerLockState($owner, (string) ($state['operation_id'] ?? ''));
    }

    public function releaseRunnerLock(string $owner): void
    {
        $state = $this->readRunnerLockState();

        if (!is_array($state) || !hash_equals((string) ($state['owner'] ?? ''), $owner)) {
            return;
        }

        @unlink($this->runnerLockStatePath());
        @rmdir($this->runnerLockDirectory());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function runnerLockStatus(int $ttlSeconds = 3600): ?array
    {
        if (!is_file($this->runnerLockStatePath())) {
            return null;
        }

        $state = $this->readRunnerLockState() ?? [];
        $updatedAt = is_string($state['updated_at'] ?? null) ? $state['updated_at'] : null;
        $timestamp = $this->timestamp($updatedAt) ?? (filemtime($this->runnerLockStatePath()) ?: null);

        return [
            'operation_id' => is_string($state['operation_id'] ?? null) ? $state['operation_id'] : null,
            'updated_at' => $updatedAt,
            'age_seconds' => null === $timestamp ? null : max(0, time() - $timestamp),
            'stale' => $this->isRunnerLockExpired($ttlSeconds),
            'pid' => is_string($state['operation_id'] ?? null) ? $this->processInspector->readRunnerPid($state['operation_id']) : null,
            'killable' => is_string($state['operation_id'] ?? null)
                && $this->isRunnerLockExpired($ttlSeconds)
                && $this->processInspector->runnerProcessMatches($state['operation_id']),
        ];
    }

    /**
     * @return array{killed: bool, lock_cleared: bool, pid: int|null, reason: string}
     */
    public function killStaleRunner(int $ttlSeconds = 3600): array
    {
        $lock = $this->runnerLockStatus($ttlSeconds);

        if (null === $lock) {
            return ['killed' => false, 'lock_cleared' => false, 'pid' => null, 'reason' => 'missing_lock'];
        }

        if (true !== ($lock['stale'] ?? false)) {
            return ['killed' => false, 'lock_cleared' => false, 'pid' => null, 'reason' => 'lock_active'];
        }

        $operationId = $lock['operation_id'] ?? null;

        if (!is_string($operationId) || !$this->storage->validOperationId($operationId)) {
            return ['killed' => false, 'lock_cleared' => $this->clearRunnerLock(staleOnly: true, ttlSeconds: $ttlSeconds), 'pid' => null, 'reason' => 'invalid_operation'];
        }

        $pid = $this->processInspector->readRunnerPid($operationId);

        if (null === $pid) {
            return ['killed' => false, 'lock_cleared' => $this->clearRunnerLock(staleOnly: true, ttlSeconds: $ttlSeconds), 'pid' => null, 'reason' => 'missing_pid'];
        }

        if (!$this->processInspector->runnerProcessMatches($operationId)) {
            return ['killed' => false, 'lock_cleared' => false, 'pid' => $pid, 'reason' => 'process_mismatch'];
        }

        $killed = $this->processInspector->signalProcess($pid);

        if (!$killed) {
            return ['killed' => false, 'lock_cleared' => false, 'pid' => $pid, 'reason' => 'signal_failed'];
        }

        $lockCleared = $this->clearRunnerLock(staleOnly: true, ttlSeconds: $ttlSeconds);

        @unlink($this->storage->pidPath($operationId));

        return ['killed' => true, 'lock_cleared' => $lockCleared, 'pid' => $pid, 'reason' => 'killed'];
    }

    public function clearRunnerLock(bool $staleOnly = true, int $ttlSeconds = 3600): bool
    {
        if (!is_file($this->runnerLockStatePath())) {
            return false;
        }

        if ($staleOnly) {
            if (!$this->isRunnerLockExpired($ttlSeconds) || !$this->runnerLockCanBeCleared($ttlSeconds)) {
                return false;
            }
        }

        @unlink($this->runnerLockStatePath());
        @rmdir($this->runnerLockDirectory());

        return true;
    }

    public function cleanupRunnerLock(int $ttlSeconds): void
    {
        if (!$this->isRunnerLockExpired($ttlSeconds)) {
            return;
        }

        $this->clearRunnerLock(staleOnly: true, ttlSeconds: $ttlSeconds);
    }

    private function runnerLockDirectory(): string
    {
        return $this->storage->directory().'/'.self::RUNNER_LOCK_DIRECTORY;
    }

    private function runnerLockStatePath(): string
    {
        return $this->runnerLockDirectory().'/'.self::RUNNER_LOCK_STATE;
    }

    private function runnerLockName(): string
    {
        return 'system.live_operation.'.$this->safeEnvironment().'.runner';
    }

    private function writeRunnerLockState(string $owner, string $operationId): void
    {
        $directory = $this->runnerLockDirectory();
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Live operation runner lock directory "%s" could not be created.', $directory));
        }

        file_put_contents($this->runnerLockStatePath(), json_encode([
            'owner' => $owner,
            'operation_id' => $operationId,
            'updated_at' => $this->now(),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readRunnerLockState(): ?array
    {
        $path = $this->runnerLockStatePath();

        if (!is_file($path)) {
            return null;
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function isRunnerLockExpired(int $ttlSeconds): bool
    {
        if (!is_file($this->runnerLockStatePath())) {
            return false;
        }

        $state = $this->readRunnerLockState();
        $timestamp = is_array($state) ? $this->timestamp($state['updated_at'] ?? null) : null;
        $timestamp ??= filemtime($this->runnerLockStatePath()) ?: null;

        return null !== $timestamp && $timestamp <= time() - max(0, $ttlSeconds);
    }

    private function runnerLockCanBeCleared(int $ttlSeconds): bool
    {
        $lock = $this->runnerLockFactory->createLock($this->runnerLockName(), max(1, $ttlSeconds), autoRelease: false);

        if (!$lock->acquire(false)) {
            return false;
        }

        $lock->release();

        return true;
    }

    private function timestamp(mixed $value): ?int
    {
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        $timestamp = strtotime($value);

        return false === $timestamp ? null : $timestamp;
    }

    private function safeEnvironment(): string
    {
        $environment = preg_replace('/[^a-zA-Z0-9_.-]+/', '_', trim($this->environment));

        return is_string($environment) && '' !== $environment ? $environment : 'default';
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format(DATE_ATOM);
    }
}
