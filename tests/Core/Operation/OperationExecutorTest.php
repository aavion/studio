<?php

declare(strict_types=1);

namespace App\Tests\Core\Operation;

use App\Core\ActionLog\ActionLogStatus;
use App\Core\DryRun\DryRunAction;
use App\Core\DryRun\DryRunRisk;
use App\Core\Message\Message;
use App\Core\Message\WorkflowResultMessageReporterInterface;
use App\Core\Operation\ActionQueue;
use App\Core\Operation\OperationActionInterface;
use App\Core\Operation\OperationExecutor;
use App\Core\Operation\OperationMessageCode;
use App\Core\Operation\OperationMessageKey;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;
use App\Core\Workflow\WorkflowResult;
use App\Core\Workflow\WorkflowStatus;
use App\Tests\Support\NullWorkflowResultMessageReporter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OperationExecutorTest extends TestCase
{
    public function testItBuildsDryRunPlanFromActionQueue(): void
    {
        $queue = ActionQueue::create('import', [
            new TestOperationAction('copy_file', 'Copy file', WorkflowResult::success()),
        ], context: [
            'extension' => 'demo',
        ])->add(new TestOperationAction('write_config', 'Write config', WorkflowResult::success(), DryRunRisk::Medium));

        $plan = (new OperationExecutor(new NullWorkflowResultMessageReporter()))->planQueue($queue);

        self::assertSame('import', $plan->name());
        self::assertSame(['copy_file' => 1, 'write_config' => 1], $plan->actionCounts());
        self::assertSame(DryRunRisk::Medium, $plan->highestRisk());
        self::assertSame(['extension' => 'demo'], $plan->context());
        self::assertCount(2, $queue);
    }

    public function testItExecutesSuccessfulActions(): void
    {
        $execution = (new OperationExecutor(new NullWorkflowResultMessageReporter()))->executeQueue(ActionQueue::create('success', [
            new TestOperationAction('copy_file', 'Copy file', WorkflowResult::success(null, ['path' => 'target.txt'])),
            new TestOperationAction('compile_assets', 'Compile assets', WorkflowResult::success()),
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
            new TestOperationAction('first', 'First action', WorkflowResult::success()),
            new TestOperationAction('second', 'Second action', WorkflowResult::success()),
        ], context: [
            'run_id' => 'abc',
        ]);

        $execution = (new OperationExecutor(new NullWorkflowResultMessageReporter()))->executeQueue($queue);

        self::assertTrue($execution->result()->isSuccess());
        self::assertSame('abc', $execution->result()->context()['run_id']);
        self::assertSame('First action', $execution->actionLog()->entries()[0]->name());
        self::assertSame('Second action', $execution->actionLog()->entries()[1]->name());
    }

    public function testItReportsActionStartAndFinishCallbacksWithProgress(): void
    {
        $started = [];
        $finished = [];
        $executor = new OperationExecutor(new NullWorkflowResultMessageReporter());

        $executor->executeQueue(ActionQueue::create('callbacks', [
            new TestOperationAction('first', 'First action', WorkflowResult::success()),
            new TestOperationAction('second', 'Second action', WorkflowResult::success()),
        ]), function ($entry, int $index, int $total, $result) use (&$finished): void {
            $finished[] = [$entry->name(), $index, $total, $result->isSuccess()];
        }, function ($entry, int $index, int $total, $action) use (&$started): void {
            $started[] = [$entry->name(), $index, $total, $action->type()];
        });

        self::assertSame([
            ['First action', 1, 2, 'first'],
            ['Second action', 2, 2, 'second'],
        ], $started);
        self::assertSame([
            ['First action', 1, 2, true],
            ['Second action', 2, 2, true],
        ], $finished);
    }

    public function testItStopsOnFailureByDefault(): void
    {
        $issue = Message::create('import.failed', 'message.import.failed');

        $execution = (new OperationExecutor(new NullWorkflowResultMessageReporter()))->executeQueue(ActionQueue::create('failure', [
            new TestOperationAction('write_file', 'Write file', WorkflowResult::failed([$issue])),
            new TestOperationAction('compile_assets', 'Compile assets', WorkflowResult::success()),
        ]));

        self::assertSame(WorkflowStatus::Failed, $execution->result()->status());
        self::assertSame([$issue], $execution->result()->issues());
        self::assertCount(1, $execution->actionLog()->entries());
        self::assertSame(ActionLogStatus::Failed, $execution->actionLog()->entries()[0]->status());
    }

    public function testItCanContinueAfterRecoverableIssues(): void
    {
        $issue = Message::create('import.review', 'message.import.review');

        $execution = (new OperationExecutor(new NullWorkflowResultMessageReporter()))->executeQueue(ActionQueue::create('continue', [
            new TestOperationAction('review', 'Review change', WorkflowResult::requiresReview(null, [$issue])),
            new TestOperationAction('compile_assets', 'Compile assets', WorkflowResult::success()),
        ], stopOnFailure: false));

        self::assertSame(WorkflowStatus::RequiresReview, $execution->result()->status());
        self::assertSame([$issue], $execution->result()->issues());
        self::assertSame([
            'success' => 1,
            'warning' => 1,
        ], $execution->actionLog()->statusCounts());
    }

    public function testItPreservesReviewRequiredActionContext(): void
    {
        $issue = Message::info(OperationMessageCode::OPERATION_ACTION_REQUIRED, OperationMessageKey::OPERATION_ACTION_REQUIRED, [
            '%operation%' => 'Install extension',
        ]);

        $execution = (new OperationExecutor(new NullWorkflowResultMessageReporter()))->executeQueue(ActionQueue::create('continue', [
            new TestOperationAction('review', 'Review change', WorkflowResult::requiresReview(null, [$issue], [
                'live_operation_continuation' => [
                    'operation' => 'extension.install.apply',
                    'payload' => ['install_id' => 'abc'],
                    'label' => 'Install extension',
                ],
            ])),
        ], context: [
            'operation' => 'extension.install.verify',
        ]));

        self::assertSame(WorkflowStatus::RequiresReview, $execution->result()->status());
        self::assertSame('extension.install.verify', $execution->result()->context()['operation']);
        self::assertSame('extension.install.apply', $execution->result()->context()['live_operation_continuation']['operation']);
    }

    public function testItPreservesFailedStatusWhenContinuingAfterFailures(): void
    {
        $issue = Message::create('import.failed', 'message.import.failed');

        $execution = (new OperationExecutor(new NullWorkflowResultMessageReporter()))->executeQueue(ActionQueue::create('continue failed', [
            new TestOperationAction('write_file', 'Write file', WorkflowResult::failed([$issue])),
            new TestOperationAction('compile_assets', 'Compile assets', WorkflowResult::success()),
        ], stopOnFailure: false));

        self::assertSame(WorkflowStatus::Failed, $execution->result()->status());
        self::assertSame([$issue], $execution->result()->issues());
        self::assertSame([
            'failed' => 1,
            'success' => 1,
        ], $execution->actionLog()->statusCounts());
    }

    public function testItPreservesBlockedStatusWhenContinuingAfterBlockedActions(): void
    {
        $issue = Message::create('import.blocked', 'message.import.blocked');

        $execution = (new OperationExecutor(new NullWorkflowResultMessageReporter()))->executeQueue(ActionQueue::create('continue blocked', [
            new TestOperationAction('copy_file', 'Copy file', WorkflowResult::blocked([$issue])),
            new TestOperationAction('compile_assets', 'Compile assets', WorkflowResult::success()),
        ], stopOnFailure: false));

        self::assertSame(WorkflowStatus::Blocked, $execution->result()->status());
        self::assertSame([$issue], $execution->result()->issues());
        self::assertSame([
            'failed' => 1,
            'success' => 1,
        ], $execution->actionLog()->statusCounts());
    }

    public function testActionQueueCanDisableStopOnFailure(): void
    {
        $issue = Message::create('import.review', 'message.import.review');
        $queue = ActionQueue::create('continue', [
            new TestOperationAction('review', 'Review change', WorkflowResult::requiresReview(null, [$issue])),
            new TestOperationAction('compile_assets', 'Compile assets', WorkflowResult::success()),
        ], stopOnFailure: false);

        $execution = (new OperationExecutor(new NullWorkflowResultMessageReporter()))->executeQueue($queue);

        self::assertSame(WorkflowStatus::RequiresReview, $execution->result()->status());
        self::assertCount(2, $execution->actionLog()->entries());
    }

    public function testItConvertsExceptionsToFailedResults(): void
    {
        $execution = (new OperationExecutor(new NullWorkflowResultMessageReporter()))->executeQueue(ActionQueue::create('exception', [
            new ThrowingOperationAction(),
        ]));

        self::assertSame(WorkflowStatus::Failed, $execution->result()->status());
        self::assertSame('operation.exception', $execution->result()->firstIssue()?->code());
        self::assertSame(RuntimeException::class, $execution->result()->firstIssue()?->context()['exception']);
        self::assertSame(ActionLogStatus::Failed, $execution->actionLog()->entries()[0]->status());
    }

    public function testItPassesActionResultsToTheMessageReporter(): void
    {
        $logger = new RecordingWorkflowResultMessageReporter();
        $message = Message::info(ExtensionMessageCode::EXTENSION_DISCOVERY_COMPLETED, ExtensionMessageKey::EXTENSION_DISCOVERY_COMPLETED, [
            '%count%' => 1,
        ]);

        $execution = (new OperationExecutor($logger))->executeQueue(ActionQueue::create('logged queue', [
            new TestOperationAction('discover', 'Discover extensions', WorkflowResult::success(context: [
                'database_password' => 'secret',
            ], messages: [$message])),
        ]));

        self::assertTrue($execution->result()->isSuccess());
        self::assertCount(1, $logger->records);
        self::assertSame([$message], $logger->records[0]['result']->messages());
        self::assertSame([
            'queue' => 'logged queue',
            'action' => 'Discover extensions',
            'type' => 'discover',
            'index' => 1,
            'total' => 1,
        ], $logger->records[0]['context']);
    }

    public function testItExportsExecutionPayload(): void
    {
        $execution = (new OperationExecutor(new NullWorkflowResultMessageReporter()))->executeQueue(ActionQueue::create('success', [
            new TestOperationAction('copy_file', 'Copy file', WorkflowResult::success(null, ['path' => 'target.txt'])),
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
     * @param WorkflowResult<mixed> $result
     */
    public function __construct(
        private string $type,
        private string $label,
        private WorkflowResult $result,
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

    public function execute(): WorkflowResult
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

    public function execute(): WorkflowResult
    {
        throw new RuntimeException('Boom.');
    }
}

final class RecordingWorkflowResultMessageReporter implements WorkflowResultMessageReporterInterface
{
    /**
     * @var list<array{result: WorkflowResult<mixed>, context: array<string, mixed>}>
     */
    public array $records = [];

    public function report(WorkflowResult $result, array $operationContext = []): WorkflowResult
    {
        $this->records[] = [
            'result' => $result,
            'context' => $operationContext,
        ];

        return $result;
    }
}
