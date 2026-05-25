<?php

declare(strict_types=1);

namespace App\Core\Operation;

use App\Core\ActionLog\ActionLog;
use App\Core\ActionLog\ActionLogEntry;
use App\Core\ActionLog\ActionLogStatus;
use App\Core\DryRun\DryRunPlan;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\WorkflowResultMessageReporterInterface;
use App\Core\Message\Message;
use App\Core\Workflow\WorkflowResult;
use App\Core\Workflow\WorkflowStatus;
use Throwable;

final class OperationExecutor
{
    public function __construct(private WorkflowResultMessageReporterInterface $messageReporter)
    {
    }

    public function planQueue(ActionQueue $queue): DryRunPlan
    {
        $plan = DryRunPlan::create($queue->name(), $queue->context());

        foreach ($queue as $action) {
            $plan = $plan->add($action->dryRun());
        }

        return $plan;
    }

    public function executeQueue(ActionQueue $queue, ?callable $onEntry = null, ?callable $onStart = null): OperationExecution
    {
        $log = ActionLog::create();
        $issues = [];
        $messages = [];
        $context = $queue->context();
        $status = WorkflowStatus::Success;
        $index = 0;
        $total = count($queue);

        foreach ($queue as $action) {
            ++$index;
            $entry = ActionLogEntry::pending($action->label(), [
                'type' => $action->type(),
            ])->start();
            if (null !== $onStart) {
                $onStart($entry, $index, $total, $action);
            }

            try {
                $result = $action->execute();
            } catch (Throwable $error) {
                $result = WorkflowResult::failed([
                    Message::exception(MessageCode::OPERATION_EXCEPTION, MessageKey::OPERATION_EXCEPTION, context: [
                        'action' => $action->label(),
                        'type' => $action->type(),
                        'exception' => $error::class,
                        'message' => $error->getMessage(),
                    ]),
                ]);
            }

            array_push($issues, ...$result->issues());
            array_push($messages, ...$result->messages());
            $status = $this->highestSeverity($status, $result->status());
            $this->reportResult($result, $queue, $action, $index, $total);
            $finishedEntry = $entry->finish(
                $this->statusForResult($result),
                $result->issues(),
                $result->context(),
                messages: $result->messages(),
            );
            $log = $log->add($finishedEntry);
            if (null !== $onEntry) {
                $onEntry($finishedEntry, $index, $total, $result);
            }

            if (!$result->isSuccess() && $queue->stopOnFailure()) {
                return new OperationExecution($log, $this->resultForIssues($result->status(), $issues, $context, $messages));
            }
        }

        if ([] !== $issues) {
            return new OperationExecution($log, $this->resultForIssues($status, $issues, $context, $messages));
        }

        return new OperationExecution($log, WorkflowResult::success(context: $context, messages: $messages));
    }

    /**
     * @param WorkflowResult<mixed> $result
     */
    private function statusForResult(WorkflowResult $result): ActionLogStatus
    {
        return match ($result->status()) {
            WorkflowStatus::Success => ActionLogStatus::Success,
            WorkflowStatus::Invalid, WorkflowStatus::RequiresReview => ActionLogStatus::Warning,
            WorkflowStatus::Blocked, WorkflowStatus::Failed => ActionLogStatus::Failed,
        };
    }

    /**
     * @param list<Message> $issues
     * @param array<string, mixed> $context
     *
     * @return WorkflowResult<mixed>
     */
    private function resultForIssues(WorkflowStatus $status, array $issues, array $context = [], array $messages = []): WorkflowResult
    {
        return match ($status) {
            WorkflowStatus::Invalid => WorkflowResult::invalid($issues, $context, $messages),
            WorkflowStatus::RequiresReview => WorkflowResult::requiresReview(null, $issues, $context, $messages),
            WorkflowStatus::Blocked => WorkflowResult::blocked($issues, $context, $messages),
            WorkflowStatus::Failed => WorkflowResult::failed($issues, $context, $messages),
            WorkflowStatus::Success => WorkflowResult::success(context: $context, messages: $messages),
        };
    }

    private function highestSeverity(WorkflowStatus $current, WorkflowStatus $next): WorkflowStatus
    {
        return $this->severity($next) > $this->severity($current) ? $next : $current;
    }

    /**
     * @param WorkflowResult<mixed> $result
     */
    private function reportResult(WorkflowResult $result, ActionQueue $queue, OperationActionInterface $action, int $index, int $total): void
    {
        $this->messageReporter->report($result, [
            'queue' => $queue->name(),
            'action' => $action->label(),
            'type' => $action->type(),
            'index' => $index,
            'total' => $total,
        ]);
    }

    private function severity(WorkflowStatus $status): int
    {
        return match ($status) {
            WorkflowStatus::Success => 0,
            WorkflowStatus::RequiresReview => 1,
            WorkflowStatus::Invalid => 2,
            WorkflowStatus::Blocked => 3,
            WorkflowStatus::Failed => 4,
        };
    }
}
