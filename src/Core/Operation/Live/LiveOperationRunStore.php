<?php

declare(strict_types=1);

namespace App\Core\Operation\Live;

use App\Core\ActionLog\ActionLogEntry;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Log\OperationLoggerInterface;
use App\Core\Workflow\WorkflowResult;
use RuntimeException;
use Throwable;

final readonly class LiveOperationRunStore
{
    private const STATUS_QUEUED = 'queued';
    private const STATUS_RUNNING = 'running';
    private const STATUS_SUCCESS = 'success';
    private const STATUS_REQUIRES_REVIEW = 'requires_review';
    private const STATUS_FAILED = 'failed';
    private const TERMINAL_STATUSES = [self::STATUS_SUCCESS, self::STATUS_REQUIRES_REVIEW, self::STATUS_FAILED];
    private const RUNNER_LOCK_DIRECTORY = 'runner.lock';
    private const RUNNER_LOCK_STATE = 'state.json';

    public function __construct(
        private string $projectDir,
        private string $environment,
        private int $staleAfterSeconds = 3600,
        private ?OperationLoggerInterface $operationLogger = null,
    )
    {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{operation_id: string, token: string, operation: string, label: string, status: string}
     */
    public function create(string $operation, array $payload, string $label): array
    {
        $operationId = bin2hex(random_bytes(16));
        $token = bin2hex(random_bytes(24));
        $state = [
            'operation_id' => $operationId,
            'token' => $token,
            'operation' => $operation,
            'label' => $label,
            'payload' => $payload,
            'status' => self::STATUS_QUEUED,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
            'started_at' => null,
            'finished_at' => null,
            'cursor' => 0,
            'progress' => ['index' => 0, 'total' => 0],
            'entries' => [],
            'result' => null,
        ];

        $this->write($operationId, $state);

        return [
            'operation_id' => $operationId,
            'token' => $token,
            'operation' => $operation,
            'label' => $label,
            'status' => self::STATUS_QUEUED,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function read(string $operationId): ?array
    {
        if (!$this->validOperationId($operationId)) {
            return null;
        }

        $path = $this->path($operationId);

        if (!is_file($path)) {
            return null;
        }

        try {
            $contents = file_get_contents($path);

            if (!is_string($contents)) {
                return null;
            }

            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            return null;
        }
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
        $directory = $this->directory();

        if (!is_dir($directory)) {
            return [];
        }

        $summaries = [];

        foreach (glob($directory.'/*.json') ?: [] as $path) {
            $operationId = basename($path, '.json');

            if (!$this->validOperationId($operationId)) {
                continue;
            }

            $this->markStaleIfNeeded($operationId);
            $state = $this->read($operationId);

            if (null === $state) {
                continue;
            }

            $summaries[] = $this->summaryFromState($state);
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
        if (!$this->validOperationId($operationId)) {
            return null;
        }

        $this->markStaleIfNeeded($operationId);
        $state = $this->read($operationId);

        if (null === $state) {
            return null;
        }

        $entries = [];

        foreach (is_array($state['entries'] ?? null) ? $state['entries'] : [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $entries[] = [
                'cursor' => (int) ($entry['cursor'] ?? 0),
                'index' => (int) ($entry['index'] ?? 0),
                'total' => (int) ($entry['total'] ?? 0),
                'name' => (string) ($entry['name'] ?? ''),
                'status' => (string) ($entry['status'] ?? ''),
                'started_at' => $entry['started_at'] ?? null,
                'finished_at' => $entry['finished_at'] ?? null,
                'issues' => $this->messageList($entry['issues'] ?? []),
                'messages' => $this->messageList($entry['messages'] ?? []),
            ];
        }

        $result = is_array($state['result'] ?? null) ? $state['result'] : null;

        return [
            ...$this->summaryFromState($state),
            'entries' => $entries,
            'result' => null === $result ? null : [
                'status' => is_string($result['status'] ?? null) ? $result['status'] : null,
                'issues' => $this->messageList($result['issues'] ?? []),
                'messages' => $this->messageList($result['messages'] ?? []),
                'can_continue' => null !== $this->continuationFromResult($result),
            ],
        ];
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
        if (!$this->validOperationId($operationId)) {
            return null;
        }

        $path = $this->path($operationId);

        if (!is_file($path)) {
            return null;
        }

        $handle = fopen($path, 'c+');

        if (false === $handle) {
            return null;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return null;
            }

            $contents = stream_get_contents($handle, offset: 0);
            $state = is_string($contents) && '' !== $contents
                ? json_decode($contents, true, flags: JSON_THROW_ON_ERROR)
                : null;

            if (!is_array($state)
                || !hash_equals((string) ($state['token'] ?? ''), $token)
                || self::STATUS_QUEUED !== (string) ($state['status'] ?? '')
            ) {
                return null;
            }

            $state['status'] = self::STATUS_RUNNING;
            $state['started_at'] ??= $this->now();
            $state['updated_at'] = $this->now();
            $state['progress'] = ['index' => 0, 'total' => 0];

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return $state;
        } catch (Throwable) {
            return null;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function markRunning(string $operationId, int $total = 0): void
    {
        $this->mutate($operationId, static function (array $state) use ($total): array {
            $state['status'] = self::STATUS_RUNNING;
            $state['started_at'] ??= (new \DateTimeImmutable())->format(DATE_ATOM);
            $state['progress'] = ['index' => 0, 'total' => max(0, $total)];

            return $state;
        });
    }

    public function acquireRunnerLock(string $operationId, int $ttlSeconds = 3600): ?LiveOperationRunLock
    {
        if (!$this->validOperationId($operationId)) {
            return null;
        }

        $owner = bin2hex(random_bytes(16));

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            if (@mkdir($this->runnerLockDirectory(), 0775)) {
                $this->writeRunnerLockState($owner, $operationId);

                return new LiveOperationRunLock($this, $owner);
            }

            if (!is_dir($this->runnerLockDirectory()) || !$this->isRunnerLockExpired($ttlSeconds)) {
                return null;
            }

            $this->cleanupRunnerLock($ttlSeconds);
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
        if (!is_dir($this->runnerLockDirectory())) {
            return null;
        }

        $state = $this->readRunnerLockState() ?? [];
        $updatedAt = is_string($state['updated_at'] ?? null) ? $state['updated_at'] : null;
        $timestamp = $this->timestamp($updatedAt) ?? (filemtime($this->runnerLockDirectory()) ?: null);

        return [
            'operation_id' => is_string($state['operation_id'] ?? null) ? $state['operation_id'] : null,
            'updated_at' => $updatedAt,
            'age_seconds' => null === $timestamp ? null : max(0, time() - $timestamp),
            'stale' => $this->isRunnerLockExpired($ttlSeconds),
            'pid' => is_string($state['operation_id'] ?? null) ? $this->readRunnerPid($state['operation_id']) : null,
            'killable' => is_string($state['operation_id'] ?? null)
                && $this->isRunnerLockExpired($ttlSeconds)
                && $this->runnerProcessMatches($state['operation_id']),
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

        if (!is_string($operationId) || !$this->validOperationId($operationId)) {
            return ['killed' => false, 'lock_cleared' => $this->clearRunnerLock(staleOnly: true, ttlSeconds: $ttlSeconds), 'pid' => null, 'reason' => 'invalid_operation'];
        }

        $pid = $this->readRunnerPid($operationId);

        if (null === $pid) {
            return ['killed' => false, 'lock_cleared' => $this->clearRunnerLock(staleOnly: true, ttlSeconds: $ttlSeconds), 'pid' => null, 'reason' => 'missing_pid'];
        }

        if (!$this->runnerProcessMatches($operationId)) {
            return ['killed' => false, 'lock_cleared' => false, 'pid' => $pid, 'reason' => 'process_mismatch'];
        }

        $killed = $this->signalProcess($pid);

        if (!$killed) {
            return ['killed' => false, 'lock_cleared' => false, 'pid' => $pid, 'reason' => 'signal_failed'];
        }

        $lockCleared = $this->clearRunnerLock(staleOnly: true, ttlSeconds: $ttlSeconds);

        @unlink($this->pidPath($operationId));

        return ['killed' => true, 'lock_cleared' => $lockCleared, 'pid' => $pid, 'reason' => 'killed'];
    }

    public function clearRunnerLock(bool $staleOnly = true, int $ttlSeconds = 3600): bool
    {
        if (!is_dir($this->runnerLockDirectory())) {
            return false;
        }

        if ($staleOnly && !$this->isRunnerLockExpired($ttlSeconds)) {
            return false;
        }

        @unlink($this->runnerLockStatePath());

        return @rmdir($this->runnerLockDirectory());
    }

    public function setTotal(string $operationId, int $total): void
    {
        $this->mutate($operationId, static function (array $state) use ($total): array {
            $progress = is_array($state['progress'] ?? null) ? $state['progress'] : [];
            $state['progress'] = [
                'index' => (int) ($progress['index'] ?? 0),
                'total' => max(0, $total),
            ];

            return $state;
        });
    }

    public function appendEntry(string $operationId, ActionLogEntry $entry, int $index, int $total): void
    {
        $this->mutate($operationId, function (array $state) use ($entry, $index, $total): array {
            $cursor = ((int) ($state['cursor'] ?? 0)) + 1;
            $state['cursor'] = $cursor;
            $entries = is_array($state['entries'] ?? null) ? $state['entries'] : [];
            $entries[] = [
                'cursor' => $cursor,
                'index' => $index,
                'total' => $total,
                ...$entry->toArray(),
            ];
            $state['entries'] = $entries;
            $state['progress'] = ['index' => $index, 'total' => $total];

            return $state;
        });
    }

    /**
     * @param array<string, mixed> $result
     */
    public function finish(string $operationId, bool $success, array $result): void
    {
        $this->mutate($operationId, static function (array $state) use ($success, $result): array {
            $state['status'] = self::STATUS_REQUIRES_REVIEW === ($result['status'] ?? null)
                ? self::STATUS_REQUIRES_REVIEW
                : ($success ? self::STATUS_SUCCESS : self::STATUS_FAILED);
            $state['finished_at'] = (new \DateTimeImmutable())->format(DATE_ATOM);
            $state['result'] = $result;

            return $state;
        });
        $this->logFinished($operationId);
    }

    /**
     * @return array{checked: int, removed: int}
     */
    public function cleanup(int $ttlSeconds = 3600): array
    {
        $directory = $this->directory();

        if (!is_dir($directory)) {
            return ['checked' => 0, 'removed' => 0];
        }

        $checked = 0;
        $removed = 0;
        $cutoff = time() - max(0, $ttlSeconds);

        foreach (glob($directory.'/*.json') ?: [] as $path) {
            ++$checked;
            $operationId = basename($path, '.json');
            $state = $this->read($operationId);

            if (null === $state || (!$this->isExpiredTerminal($state, $cutoff) && !$this->isExpiredActive($state, $cutoff))) {
                continue;
            }

            @unlink($path);
            @unlink($this->outputPath($operationId));
            @unlink($this->pidPath($operationId));
            ++$removed;
        }

        $this->cleanupRunnerLock($ttlSeconds);

        return ['checked' => $checked, 'removed' => $removed];
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

        $entries = array_values(array_filter(
            is_array($state['entries'] ?? null) ? $state['entries'] : [],
            static fn (mixed $entry): bool => is_array($entry) && (int) ($entry['cursor'] ?? 0) > $cursor,
        ));
        $status = (string) ($state['status'] ?? self::STATUS_QUEUED);
        $terminal = in_array($status, self::TERMINAL_STATUSES, true);

        return [
            'operation_id' => (string) $state['operation_id'],
            'operation' => (string) $state['operation'],
            'label' => (string) $state['label'],
            'status' => $status,
            'created_at' => $state['created_at'] ?? null,
            'updated_at' => $state['updated_at'] ?? null,
            'started_at' => $state['started_at'] ?? null,
            'finished_at' => $state['finished_at'] ?? null,
            'cursor' => (int) ($state['cursor'] ?? 0),
            'cursor_max' => (int) ($state['cursor'] ?? 0),
            'progress' => is_array($state['progress'] ?? null) ? $state['progress'] : ['index' => 0, 'total' => 0],
            'entries' => $entries,
            'result' => $terminal ? ($state['result'] ?? null) : null,
            'can_continue' => $terminal && self::STATUS_REQUIRES_REVIEW === $status && null !== $this->continuationFromResult($state['result'] ?? null),
            'next_poll_ms' => $terminal ? null : 750,
        ];
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

        return $this->continuationFromResult($state['result'] ?? null);
    }

    /**
     * @return array{operation: string, payload: array<string, mixed>, label: string}|null
     */
    public function continuationForOperator(string $operationId): ?array
    {
        if (!$this->validOperationId($operationId)) {
            return null;
        }

        $state = $this->read($operationId);

        if (null === $state || self::STATUS_REQUIRES_REVIEW !== (string) ($state['status'] ?? '')) {
            return null;
        }

        return $this->continuationFromResult($state['result'] ?? null);
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $mutator
     */
    private function mutate(string $operationId, callable $mutator): void
    {
        $state = $this->read($operationId);

        if (null === $state) {
            throw new RuntimeException(sprintf('Live operation "%s" does not exist.', $operationId));
        }

        $this->write($operationId, $mutator($state));
    }

    /**
     * @param array<string, mixed> $state
     */
    private function write(string $operationId, array $state): void
    {
        if (!$this->validOperationId($operationId)) {
            throw new RuntimeException('Live operation id is invalid.');
        }

        $directory = $this->directory();

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Live operation directory "%s" could not be created.', $directory));
        }

        $state['updated_at'] = $this->now();

        file_put_contents($this->path($operationId), json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), LOCK_EX);
    }

    private function markStaleIfNeeded(string $operationId): void
    {
        $state = $this->read($operationId);

        if (null === $state || !$this->isStale($state)) {
            return;
        }

        $operation = (string) ($state['operation'] ?? 'unknown');
        $result = WorkflowResult::failed([
            Message::warning(
                MessageCode::E_OPERATION_FAILED,
                MessageKey::OPERATION_STALE,
                ['%operation%' => $operation],
                ['operation' => $operation, 'operation_id' => $operationId],
            ),
        ], ['operation' => $operation, 'operation_id' => $operationId]);

        $this->finish($operationId, false, $result->toArray());
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

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    private function summaryFromState(array $state): array
    {
        $result = is_array($state['result'] ?? null) ? $state['result'] : null;
        $issues = is_array($result['issues'] ?? null) ? $result['issues'] : [];
        $firstIssue = $issues[0] ?? null;

        return [
            'operation_id' => (string) ($state['operation_id'] ?? ''),
            'operation' => (string) ($state['operation'] ?? ''),
            'label' => (string) ($state['label'] ?? ''),
            'status' => (string) ($state['status'] ?? self::STATUS_QUEUED),
            'created_at' => $state['created_at'] ?? null,
            'updated_at' => $state['updated_at'] ?? null,
            'started_at' => $state['started_at'] ?? null,
            'finished_at' => $state['finished_at'] ?? null,
            'cursor' => (int) ($state['cursor'] ?? 0),
            'progress' => is_array($state['progress'] ?? null) ? $state['progress'] : ['index' => 0, 'total' => 0],
            'result_status' => is_array($result) ? ($result['status'] ?? null) : null,
            'issue' => is_array($firstIssue) ? $firstIssue : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function messageList(mixed $messages): array
    {
        if (!is_array($messages)) {
            return [];
        }

        $list = [];

        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            $list[] = [
                'level' => is_string($message['level'] ?? null) ? $message['level'] : null,
                'code' => is_string($message['code'] ?? null) ? $message['code'] : null,
                'translation_key' => is_string($message['translation_key'] ?? null) ? $message['translation_key'] : null,
                'parameters' => is_array($message['parameters'] ?? null) ? $message['parameters'] : [],
            ];
        }

        return $list;
    }

    /**
     * @return array{operation: string, payload: array<string, mixed>, label: string}|null
     */
    private function continuationFromResult(mixed $result): ?array
    {
        if (!is_array($result)) {
            return null;
        }

        $context = $result['context'] ?? null;
        $continuation = is_array($context) ? ($context['live_operation_continuation'] ?? null) : null;

        if (!is_array($continuation)) {
            return null;
        }

        $operation = $continuation['operation'] ?? null;
        $payload = $continuation['payload'] ?? [];
        $label = $continuation['label'] ?? null;

        if (!is_string($operation) || '' === trim($operation) || !is_array($payload)) {
            return null;
        }

        return [
            'operation' => trim($operation),
            'payload' => $payload,
            'label' => is_string($label) && '' !== trim($label) ? trim($label) : trim($operation),
        ];
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

    private function path(string $operationId): string
    {
        return $this->directory().'/'.$operationId.'.json';
    }

    private function runnerLockDirectory(): string
    {
        return $this->directory().'/'.self::RUNNER_LOCK_DIRECTORY;
    }

    private function runnerLockStatePath(): string
    {
        return $this->runnerLockDirectory().'/'.self::RUNNER_LOCK_STATE;
    }

    public function outputPath(string $operationId): string
    {
        if (!$this->validOperationId($operationId)) {
            throw new RuntimeException('Live operation id is invalid.');
        }

        return $this->directory().'/'.$operationId.'.out';
    }

    public function pidPath(string $operationId): string
    {
        if (!$this->validOperationId($operationId)) {
            throw new RuntimeException('Live operation id is invalid.');
        }

        return $this->directory().'/'.$operationId.'.pid';
    }

    private function directory(): string
    {
        return rtrim($this->projectDir, '/').'/var/operations/'.$this->safeEnvironment();
    }

    private function safeEnvironment(): string
    {
        $environment = preg_replace('/[^a-zA-Z0-9_.-]+/', '_', trim($this->environment));

        return is_string($environment) && '' !== $environment ? $environment : 'default';
    }

    private function validOperationId(string $operationId): bool
    {
        return 1 === preg_match('/^[a-f0-9]{32}$/', $operationId);
    }

    private function readRunnerPid(string $operationId): ?int
    {
        if (!$this->validOperationId($operationId) || !is_file($this->pidPath($operationId))) {
            return null;
        }

        $pid = trim((string) file_get_contents($this->pidPath($operationId)));

        if (!ctype_digit($pid)) {
            return null;
        }

        $pidValue = (int) $pid;

        return $pidValue > 0 ? $pidValue : null;
    }

    private function runnerProcessMatches(string $operationId): bool
    {
        $pid = $this->readRunnerPid($operationId);

        if (null === $pid) {
            return false;
        }

        $command = $this->processCommand($pid);

        return null !== $command
            && str_contains($command, 'studio:operations:run')
            && str_contains($command, $operationId);
    }

    private function processCommand(int $pid): ?string
    {
        $command = sprintf('ps -p %d -o command=', $pid);
        $output = [];
        $exitCode = 1;

        @exec($command, $output, $exitCode);

        if (0 !== $exitCode || [] === $output) {
            return null;
        }

        $line = trim(implode(' ', $output));

        return '' !== $line ? $line : null;
    }

    private function signalProcess(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            @posix_kill($pid, 15);
            usleep(200000);

            if (null === $this->processCommand($pid)) {
                return true;
            }

            @posix_kill($pid, 9);
            usleep(200000);

            return null === $this->processCommand($pid);
        }

        $command = sprintf('kill -TERM %d', $pid);
        $output = [];
        $exitCode = 1;

        @exec($command, $output, $exitCode);
        usleep(200000);

        return 0 === $exitCode && null === $this->processCommand($pid);
    }

    private function writeRunnerLockState(string $owner, string $operationId): void
    {
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
        if (!is_dir($this->runnerLockDirectory())) {
            return false;
        }

        $state = $this->readRunnerLockState();
        $timestamp = is_array($state) ? $this->timestamp($state['updated_at'] ?? null) : null;
        $timestamp ??= filemtime($this->runnerLockDirectory()) ?: null;

        return null !== $timestamp && $timestamp <= time() - max(0, $ttlSeconds);
    }

    private function cleanupRunnerLock(int $ttlSeconds): void
    {
        if (!$this->isRunnerLockExpired($ttlSeconds)) {
            return;
        }

        @unlink($this->runnerLockStatePath());
        @rmdir($this->runnerLockDirectory());
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format(DATE_ATOM);
    }
}
