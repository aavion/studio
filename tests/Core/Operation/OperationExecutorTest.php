<?php

declare(strict_types=1);

namespace App\Tests\Core\Operation;

use App\Core\ActionLog\ActionLogStatus;
use App\Core\DryRun\DryRunAction;
use App\Core\DryRun\DryRunRisk;
use App\Core\Operation\ActionQueue;
use App\Core\Operation\OperationActionInterface;
use App\Core\Operation\OperationExecutor;
use App\Core\Workflow\OperationIssue;
use App\Core\Workflow\OperationResult;
use App\Core\Workflow\OperationStatus;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OperationExecutorTest extends TestCase
{
    public function testItBuildsDryRunPlanFromActionQueue(): void
    {
        $queue = ActionQueue::create('import', [
            new TestOperationAction('copy_file', 'Copy file', OperationResult::success()),
        ], context: [
            'package' => 'demo',
        ])->add(new TestOperationAction('write_config', 'Write config', OperationResult::success(), DryRunRisk::Medium));

        $plan = (new OperationExecutor())->planQueue($queue);

        self::assertSame('import', $plan->name());
        self::assertSame(['copy_file' => 1, 'write_config' => 1], $plan->actionCounts());
        self::assertSame(DryRunRisk::Medium, $plan->highestRisk());
        self::assertSame(['package' => 'demo'], $plan->context());
        self::assertCount(2, $queue);
    }

    public function testItExecutesSuccessfulActions(): void
    {
        $execution = (new OperationExecutor())->executeQueue(ActionQueue::create('success', [
            new TestOperationAction('copy_file', 'Copy file', OperationResult::success(null, ['path' => 'target.txt'])),
            new TestOperationAction('compile_assets', 'Compile assets', OperationResult::success()),
        ]));

        self::assertTrue($execution->result()->isSuccess());
        self::assertSame([
            'success' => 2,
        ], $execution->actionLog()->statusCounts());
        self::assertSame(ActionLogStatus::Success, $execution->actionLog()->entries()[0]->status());
        self::assertSame('target.txt', $execution->actionLog()->entries()[0]->context()['path']);
    }

    public function testItExecutesActionQueueInOrderWithContext(): void
    {
        $queue = ActionQueue::create('ordered', [
            new TestOperationAction('first', 'First action', OperationResult::success()),
            new TestOperationAction('second', 'Second action', OperationResult::success()),
        ], context: [
            'run_id' => 'abc',
        ]);

        $execution = (new OperationExecutor())->executeQueue($queue);

        self::assertTrue($execution->result()->isSuccess());
        self::assertSame('abc', $execution->result()->context()['run_id']);
        self::assertSame('First action', $execution->actionLog()->entries()[0]->name());
        self::assertSame('Second action', $execution->actionLog()->entries()[1]->name());
    }

    public function testItStopsOnFailureByDefault(): void
    {
        $issue = OperationIssue::create('import.failed', 'message.import.failed');

        $execution = (new OperationExecutor())->executeQueue(ActionQueue::create('failure', [
            new TestOperationAction('write_file', 'Write file', OperationResult::failed([$issue])),
            new TestOperationAction('compile_assets', 'Compile assets', OperationResult::success()),
        ]));

        self::assertSame(OperationStatus::Failed, $execution->result()->status());
        self::assertSame([$issue], $execution->result()->issues());
        self::assertCount(1, $execution->actionLog()->entries());
        self::assertSame(ActionLogStatus::Failed, $execution->actionLog()->entries()[0]->status());
    }

    public function testItCanContinueAfterRecoverableIssues(): void
    {
        $issue = OperationIssue::create('import.review', 'message.import.review');

        $execution = (new OperationExecutor())->executeQueue(ActionQueue::create('continue', [
            new TestOperationAction('review', 'Review change', OperationResult::requiresReview(null, [$issue])),
            new TestOperationAction('compile_assets', 'Compile assets', OperationResult::success()),
        ], stopOnFailure: false));

        self::assertSame(OperationStatus::RequiresReview, $execution->result()->status());
        self::assertSame([$issue], $execution->result()->issues());
        self::assertSame([
            'success' => 1,
            'warning' => 1,
        ], $execution->actionLog()->statusCounts());
    }

    public function testItPreservesFailedStatusWhenContinuingAfterFailures(): void
    {
        $issue = OperationIssue::create('import.failed', 'message.import.failed');

        $execution = (new OperationExecutor())->executeQueue(ActionQueue::create('continue failed', [
            new TestOperationAction('write_file', 'Write file', OperationResult::failed([$issue])),
            new TestOperationAction('compile_assets', 'Compile assets', OperationResult::success()),
        ], stopOnFailure: false));

        self::assertSame(OperationStatus::Failed, $execution->result()->status());
        self::assertSame([$issue], $execution->result()->issues());
        self::assertSame([
            'failed' => 1,
            'success' => 1,
        ], $execution->actionLog()->statusCounts());
    }

    public function testItPreservesBlockedStatusWhenContinuingAfterBlockedActions(): void
    {
        $issue = OperationIssue::create('import.blocked', 'message.import.blocked');

        $execution = (new OperationExecutor())->executeQueue(ActionQueue::create('continue blocked', [
            new TestOperationAction('copy_file', 'Copy file', OperationResult::blocked([$issue])),
            new TestOperationAction('compile_assets', 'Compile assets', OperationResult::success()),
        ], stopOnFailure: false));

        self::assertSame(OperationStatus::Blocked, $execution->result()->status());
        self::assertSame([$issue], $execution->result()->issues());
        self::assertSame([
            'failed' => 1,
            'success' => 1,
        ], $execution->actionLog()->statusCounts());
    }

    public function testActionQueueCanDisableStopOnFailure(): void
    {
        $issue = OperationIssue::create('import.review', 'message.import.review');
        $queue = ActionQueue::create('continue', [
            new TestOperationAction('review', 'Review change', OperationResult::requiresReview(null, [$issue])),
            new TestOperationAction('compile_assets', 'Compile assets', OperationResult::success()),
        ], stopOnFailure: false);

        $execution = (new OperationExecutor())->executeQueue($queue);

        self::assertSame(OperationStatus::RequiresReview, $execution->result()->status());
        self::assertCount(2, $execution->actionLog()->entries());
    }

    public function testItConvertsExceptionsToFailedResults(): void
    {
        $execution = (new OperationExecutor())->executeQueue(ActionQueue::create('exception', [
            new ThrowingOperationAction(),
        ]));

        self::assertSame(OperationStatus::Failed, $execution->result()->status());
        self::assertSame('operation.exception', $execution->result()->firstIssue()?->code());
        self::assertSame(RuntimeException::class, $execution->result()->firstIssue()?->context()['exception']);
        self::assertSame(ActionLogStatus::Failed, $execution->actionLog()->entries()[0]->status());
    }

    public function testItExportsExecutionPayload(): void
    {
        $execution = (new OperationExecutor())->executeQueue(ActionQueue::create('success', [
            new TestOperationAction('copy_file', 'Copy file', OperationResult::success(null, ['path' => 'target.txt'])),
        ], context: [
            'queue' => 'success',
        ]));

        $payload = $execution->toArray();

        self::assertSame('success', $payload['result']['status']);
        self::assertSame(['queue' => 'success'], $payload['result']['context']);
        self::assertSame([
            'success' => 1,
        ], $payload['action_log']['status_counts']);
        self::assertSame('Copy file', $payload['action_log']['entries'][0]['name']);
        self::assertSame('target.txt', $payload['action_log']['entries'][0]['context']['path']);
    }

    public function testActionQueueRejectsInvalidActions(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ActionQueue::create('invalid', ['not an action']);
    }
}

final readonly class TestOperationAction implements OperationActionInterface
{
    /**
     * @param OperationResult<mixed> $result
     */
    public function __construct(
        private string $type,
        private string $label,
        private OperationResult $result,
        private DryRunRisk $risk = DryRunRisk::Low,
    ) {
    }

    public function type(): string
    {
        return $this->type;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function dryRun(): DryRunAction
    {
        return DryRunAction::create($this->type, $this->label, $this->risk);
    }

    public function execute(): OperationResult
    {
        return $this->result;
    }
}

final readonly class ThrowingOperationAction implements OperationActionInterface
{
    public function type(): string
    {
        return 'throwing';
    }

    public function label(): string
    {
        return 'Throwing action';
    }

    public function dryRun(): DryRunAction
    {
        return DryRunAction::create($this->type(), $this->label());
    }

    public function execute(): OperationResult
    {
        throw new RuntimeException('Boom.');
    }
}
