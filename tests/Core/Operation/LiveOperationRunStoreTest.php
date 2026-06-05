<?php

declare(strict_types=1);

namespace App\Tests\Core\Operation;

use App\Core\ActionLog\ActionLogEntry;
use App\Core\ActionLog\ActionLogStatus;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Log\OperationLoggerInterface;
use App\Core\Operation\Live\LiveOperationRunStore;
use App\Core\Security\SecretPayloadProtector;
use App\Core\Workflow\WorkflowResult;
use App\Setup\SetupLiveOperationPayloadProtector;
use App\Tests\Support\FilesystemTestHelper;
use PHPUnit\Framework\TestCase;

final class LiveOperationRunStoreTest extends TestCase
{
    use FilesystemTestHelper;

    public function testItCreatesTokenProtectedPollingPayloads(): void
    {
        $projectDir = $this->createTemporaryDirectory('live-operation-store');
        $store = new LiveOperationRunStore($projectDir, 'test');

        $run = $store->create('backend.cache_clear', ['trigger' => 'test'], 'Cache clear');
        $store->markRunning($run['operation_id'], 1);
        $store->appendEntry(
            $run['operation_id'],
            ActionLogEntry::pending('Clear cache')->start()->finish(ActionLogStatus::Success, context: ['exit_code' => 0]),
            1,
            1,
        );
        $store->finish($run['operation_id'], true, ['status' => 'success']);

        $payload = $store->pollingPayload($run['operation_id'], $run['token']);

        self::assertIsArray($payload);
        self::assertSame('backend.cache_clear', $payload['operation']);
        self::assertSame('success', $payload['status']);
        self::assertSame(1, $payload['cursor']);
        self::assertSame('Clear cache', $payload['entries'][0]['name']);
        self::assertSame(['status' => 'success'], $payload['result']);
        self::assertStringEndsWith('/var/operations/test/'.$run['operation_id'].'.out', $store->outputPath($run['operation_id']));
        self::assertNull($store->pollingPayload($run['operation_id'], 'wrong-token'));
    }

    public function testItReportsFinishedOperationsToOperationLogger(): void
    {
        $projectDir = $this->createTemporaryDirectory('live-operation-logger');
        $logger = new RecordingOperationLogger();
        $store = new LiveOperationRunStore($projectDir, 'test', operationLogger: $logger);
        $run = $store->create('backend.cache_clear', ['token' => 'hidden'], 'Cache clear');

        $store->markRunning($run['operation_id'], 1);
        $store->finish($run['operation_id'], true, ['status' => 'success']);

        self::assertCount(1, $logger->states);
        self::assertSame($run['operation_id'], $logger->states[0]['operation_id']);
        self::assertSame('backend.cache_clear', $logger->states[0]['operation']);
        self::assertSame('success', $logger->states[0]['status']);
        self::assertSame(['token' => 'hidden'], $logger->states[0]['payload']);
    }

    public function testItCanStoreProtectedSetupPayloadsWithoutPlainSecrets(): void
    {
        $projectDir = $this->createTemporaryDirectory('live-operation-protected-setup');
        $store = new LiveOperationRunStore($projectDir, 'test');
        $protector = new SetupLiveOperationPayloadProtector(new SecretPayloadProtector('runtime-secret'));
        $payload = $protector->protect([
            'values' => [
                'admin_password' => 'Secret1!password',
                'admin_password_confirm' => 'Secret1!password',
                'database_password' => 'db-secret',
                'app_secret' => 'custom-app-secret',
            ],
        ]);

        $run = $store->create('setup.apply', $payload, 'Setup apply');
        $state = $store->read($run['operation_id']);
        $encoded = json_encode($state, JSON_THROW_ON_ERROR);

        self::assertIsArray($state);
        self::assertIsString($encoded);
        self::assertStringNotContainsString('Secret1!password', $encoded);
        self::assertStringNotContainsString('db-secret', $encoded);
        self::assertStringNotContainsString('custom-app-secret', $encoded);
        self::assertSame([
            'values' => [
                'admin_password' => 'Secret1!password',
                'admin_password_confirm' => 'Secret1!password',
                'database_password' => 'db-secret',
                'app_secret' => 'custom-app-secret',
            ],
        ], $protector->unprotect(is_array($state['payload'] ?? null) ? $state['payload'] : []));
    }

    public function testItFiltersEntriesByCursor(): void
    {
        $projectDir = $this->createTemporaryDirectory('live-operation-cursor');
        $store = new LiveOperationRunStore($projectDir, 'test');
        $run = $store->create('backend.cache_clear', [], 'Cache clear');

        $store->appendEntry($run['operation_id'], ActionLogEntry::pending('One')->start(), 1, 2);
        $store->appendEntry($run['operation_id'], ActionLogEntry::pending('Two')->start(), 2, 2);

        $payload = $store->pollingPayload($run['operation_id'], $run['token'], 1);

        self::assertIsArray($payload);
        self::assertCount(1, $payload['entries']);
        self::assertSame('Two', $payload['entries'][0]['name']);
        self::assertSame(2, $payload['cursor']);
    }

    public function testItBuildsSanitizedRetainedReports(): void
    {
        $projectDir = $this->createTemporaryDirectory('live-operation-report');
        $store = new LiveOperationRunStore($projectDir, 'test');
        $run = $store->create('backend.cache_clear', ['secret' => 'hidden'], 'Cache clear');

        $store->appendEntry($run['operation_id'], ActionLogEntry::pending('Clear cache')->start()->finish(ActionLogStatus::Success), 1, 1);
        $store->finish($run['operation_id'], true, [
            'status' => 'success',
            'issues' => [],
            'messages' => [],
            'context' => ['secret' => 'hidden'],
        ]);

        $report = $store->report($run['operation_id']);

        self::assertIsArray($report);
        self::assertSame('backend.cache_clear', $report['operation']);
        self::assertSame('Clear cache', $report['entries'][0]['name']);
        self::assertSame('success', $report['result']['status']);
        self::assertArrayNotHasKey('token', $report);
        self::assertArrayNotHasKey('payload', $report);
        self::assertArrayNotHasKey('context', $report['result']);
    }

    public function testItMarksReviewRequiredRunsAsTerminalAndExposesContinuationState(): void
    {
        $projectDir = $this->createTemporaryDirectory('live-operation-review');
        $store = new LiveOperationRunStore($projectDir, 'test');
        $run = $store->create('package.install.dry_run', [], 'Install package dry-run');
        $result = WorkflowResult::requiresReview(null, [
            Message::info(
                MessageCode::OPERATION_ACTION_REQUIRED,
                MessageKey::OPERATION_ACTION_REQUIRED,
                ['%operation%' => 'Install package'],
            ),
        ], [
            'live_operation_continuation' => [
                'operation' => 'package.install.apply',
                'payload' => ['package' => 'demo-module'],
                'label' => 'Install package',
            ],
        ]);

        $store->finish($run['operation_id'], false, $result->toArray());

        $payload = $store->pollingPayload($run['operation_id'], $run['token']);
        $continuation = $store->continuation($run['operation_id'], $run['token']);

        self::assertIsArray($payload);
        self::assertSame('requires_review', $payload['status']);
        self::assertNull($payload['next_poll_ms']);
        self::assertTrue($payload['can_continue']);
        self::assertSame('requires_review', $payload['result']['status']);
        self::assertSame([
            'operation' => 'package.install.apply',
            'payload' => ['package' => 'demo-module'],
            'label' => 'Install package',
        ], $continuation);
        self::assertNull($store->continuation($run['operation_id'], 'wrong-token'));
    }

    public function testItClaimsQueuedRunsOnlyOnce(): void
    {
        $projectDir = $this->createTemporaryDirectory('live-operation-claim');
        $store = new LiveOperationRunStore($projectDir, 'test');
        $run = $store->create('backend.cache_clear', [], 'Cache clear');

        $firstClaim = $store->claimForRunner($run['operation_id'], $run['token']);
        $secondClaim = $store->claimForRunner($run['operation_id'], $run['token']);

        self::assertIsArray($firstClaim);
        self::assertSame('running', $store->read($run['operation_id'])['status'] ?? null);
        self::assertNull($secondClaim);
        self::assertNull($store->claimForRunner($run['operation_id'], 'wrong-token'));
    }

    public function testItLocksOneRunnerAtATime(): void
    {
        $projectDir = $this->createTemporaryDirectory('live-operation-runner-lock');
        $store = new LiveOperationRunStore($projectDir, 'test');
        $run = $store->create('backend.cache_clear', [], 'Cache clear');

        $lock = $store->acquireRunnerLock($run['operation_id']);

        self::assertNotNull($lock);
        self::assertNull($store->acquireRunnerLock($run['operation_id']));

        $lock->release();

        $nextLock = $store->acquireRunnerLock($run['operation_id']);
        self::assertNotNull($nextLock);
        $nextLock->release();
    }

    public function testItCleansExpiredRunnerLocks(): void
    {
        $projectDir = $this->createTemporaryDirectory('live-operation-runner-lock-cleanup');
        $store = new LiveOperationRunStore($projectDir, 'test');
        $run = $store->create('backend.cache_clear', [], 'Cache clear');
        $lock = $store->acquireRunnerLock($run['operation_id']);

        self::assertNotNull($lock);
        $this->rewriteRunnerLock($store, [
            'owner' => 'stale-owner',
            'operation_id' => $run['operation_id'],
            'updated_at' => (new \DateTimeImmutable('-2 hours'))->format(DATE_ATOM),
        ]);

        $store->cleanup(3600);

        $nextLock = $store->acquireRunnerLock($run['operation_id']);
        self::assertNotNull($nextLock);
        $nextLock->release();
    }

    public function testItClearsStaleEmergencyKillWithoutStoredPid(): void
    {
        $projectDir = $this->createTemporaryDirectory('live-operation-runner-kill-missing-pid');
        $store = new LiveOperationRunStore($projectDir, 'test');
        $run = $store->create('backend.cache_clear', [], 'Cache clear');
        $lock = $store->acquireRunnerLock($run['operation_id']);

        self::assertNotNull($lock);
        $this->rewriteRunnerLock($store, [
            'owner' => 'stale-owner',
            'operation_id' => $run['operation_id'],
            'updated_at' => (new \DateTimeImmutable('-2 hours'))->format(DATE_ATOM),
        ]);

        $result = $store->killStaleRunner(3600);

        self::assertSame([
            'killed' => false,
            'lock_cleared' => true,
            'pid' => null,
            'reason' => 'missing_pid',
        ], $result);
        self::assertNull($store->runnerLockStatus());
    }

    public function testItRefusesEmergencyKillWhileLockIsFresh(): void
    {
        $projectDir = $this->createTemporaryDirectory('live-operation-runner-kill-fresh');
        $store = new LiveOperationRunStore($projectDir, 'test');
        $run = $store->create('backend.cache_clear', [], 'Cache clear');
        $lock = $store->acquireRunnerLock($run['operation_id']);

        self::assertNotNull($lock);
        $result = $store->killStaleRunner(3600);

        self::assertSame('lock_active', $result['reason']);
        self::assertFalse($result['killed']);
        self::assertNotNull($store->runnerLockStatus());

        $lock->release();
    }

    public function testCleanupRemovesExpiredPidFiles(): void
    {
        $projectDir = $this->createTemporaryDirectory('live-operation-cleanup-pid');
        $store = new LiveOperationRunStore($projectDir, 'test');
        $run = $store->create('backend.cache_clear', [], 'Cache clear');
        file_put_contents($store->pidPath($run['operation_id']), '12345');
        $this->rewriteState($store, $run['operation_id'], [
            'status' => 'running',
            'updated_at' => (new \DateTimeImmutable('-2 hours'))->format(DATE_ATOM),
        ]);

        $store->cleanup(3600);

        self::assertFileDoesNotExist($store->pidPath($run['operation_id']));
    }

    public function testItMarksStaleRunsAsFailed(): void
    {
        $projectDir = $this->createTemporaryDirectory('live-operation-stale');
        $store = new LiveOperationRunStore($projectDir, 'test', 1);
        $run = $store->create('backend.cache_clear', [], 'Cache clear');

        $this->rewriteState($store, $run['operation_id'], [
            'status' => 'running',
            'updated_at' => (new \DateTimeImmutable('-2 hours'))->format(DATE_ATOM),
        ]);

        $payload = $store->pollingPayload($run['operation_id'], $run['token']);

        self::assertIsArray($payload);
        self::assertSame('failed', $payload['status']);
        self::assertSame('message.operation.stale', $payload['result']['issues'][0]['translation_key']);
    }

    public function testItCleansExpiredTerminalRuns(): void
    {
        $projectDir = $this->createTemporaryDirectory('live-operation-cleanup');
        $store = new LiveOperationRunStore($projectDir, 'test');
        $run = $store->create('backend.cache_clear', [], 'Cache clear');
        file_put_contents($store->outputPath($run['operation_id']), 'runner output');
        $store->finish($run['operation_id'], true, ['status' => 'success']);
        $this->rewriteState($store, $run['operation_id'], [
            'finished_at' => (new \DateTimeImmutable('-2 hours'))->format(DATE_ATOM),
            'updated_at' => (new \DateTimeImmutable('-2 hours'))->format(DATE_ATOM),
        ]);

        $result = $store->cleanup(60);

        self::assertSame(['checked' => 1, 'removed' => 1], $result);
        self::assertNull($store->read($run['operation_id']));
        self::assertFileDoesNotExist($store->outputPath($run['operation_id']));
    }

    public function testItCleansExpiredActiveRuns(): void
    {
        $projectDir = $this->createTemporaryDirectory('live-operation-cleanup-active');
        $store = new LiveOperationRunStore($projectDir, 'test');
        $run = $store->create('backend.cache_clear', [], 'Cache clear');
        $this->rewriteState($store, $run['operation_id'], [
            'status' => 'running',
            'updated_at' => (new \DateTimeImmutable('-2 hours'))->format(DATE_ATOM),
        ]);

        $result = $store->cleanup(3600);

        self::assertSame(['checked' => 1, 'removed' => 1], $result);
        self::assertNull($store->read($run['operation_id']));
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function rewriteState(LiveOperationRunStore $store, string $operationId, array $changes): void
    {
        $path = dirname($store->outputPath($operationId)).'/'.$operationId.'.json';
        $state = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($state);
        file_put_contents($path, json_encode([...$state, ...$changes], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    /**
     * @param array<string, mixed> $state
     */
    private function rewriteRunnerLock(LiveOperationRunStore $store, array $state): void
    {
        $path = dirname($store->outputPath(str_repeat('a', 32))).'/runner.lock/state.json';

        file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }
}

final class RecordingOperationLogger implements OperationLoggerInterface
{
    /**
     * @var list<array<string, mixed>>
     */
    public array $states = [];

    public function logFinished(array $state): void
    {
        $this->states[] = $state;
    }
}
