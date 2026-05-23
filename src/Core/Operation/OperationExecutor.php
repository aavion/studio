<?php

declare(strict_types=1);

namespace App\Core\Operation;

use App\Core\ActionLog\ActionLog;
use App\Core\ActionLog\ActionLogEntry;
use App\Core\ActionLog\ActionLogStatus;
use App\Core\DryRun\DryRunPlan;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Workflow\OperationIssue;
use App\Core\Workflow\OperationResult;
use App\Core\Workflow\OperationStatus;
use Throwable;

final class OperationExecutor
{
    public function planQueue(ActionQueue $queue): DryRunPlan
    {
        $plan = DryRunPlan::create($queue->name(), $queue->context());

        foreach ($queue as $action) {
            $plan = $plan->add($action->dryRun());
        }

        return $plan;
    }

    public function executeQueue(ActionQueue $queue): OperationExecution
    {
        $log = ActionLog::create();
        $issues = [];
        $context = $queue->context();
        $status = OperationStatus::Success;

        foreach ($queue as $action) {
            $entry = ActionLogEntry::pending($action->label(), [
                'type' => $action->type(),
            ])->start();

            try {
                $result = $action->execute();
            } catch (Throwable $error) {
                $result = OperationResult::failed([
                    OperationIssue::create(MessageCode::OPERATION_EXCEPTION, MessageKey::OPERATION_EXCEPTION, context: [
                        'action' => $action->label(),
                        'type' => $action->type(),
                        'exception' => $error::class,
                        'message' => $error->getMessage(),
                    ]),
                ]);
            }

            array_push($issues, ...$result->issues());
            $status = $this->highestSeverity($status, $result->status());
            $log = $log->add($entry->finish(
                $this->statusForResult($result),
                $result->issues(),
                $result->context(),
            ));

            if (!$result->isSuccess() && $queue->stopOnFailure()) {
                return new OperationExecution($log, $this->resultForIssues($result->status(), $issues, $context));
            }
        }

        if ([] !== $issues) {
            return new OperationExecution($log, $this->resultForIssues($status, $issues, $context));
        }

        return new OperationExecution($log, OperationResult::success(context: $context));
    }

    /**
     * @param OperationResult<mixed> $result
     */
    private function statusForResult(OperationResult $result): ActionLogStatus
    {
        return match ($result->status()) {
            OperationStatus::Success => ActionLogStatus::Success,
            OperationStatus::Invalid, OperationStatus::RequiresReview => ActionLogStatus::Warning,
            OperationStatus::Blocked, OperationStatus::Failed => ActionLogStatus::Failed,
        };
    }

    /**
     * @param list<OperationIssue> $issues
     * @param array<string, mixed> $context
     *
     * @return OperationResult<mixed>
     */
    private function resultForIssues(OperationStatus $status, array $issues, array $context = []): OperationResult
    {
        return match ($status) {
            OperationStatus::Invalid => OperationResult::invalid($issues, $context),
            OperationStatus::RequiresReview => OperationResult::requiresReview(null, $issues, $context),
            OperationStatus::Blocked => OperationResult::blocked($issues, $context),
            OperationStatus::Failed => OperationResult::failed($issues, $context),
            OperationStatus::Success => OperationResult::success(context: $context),
        };
    }

    private function highestSeverity(OperationStatus $current, OperationStatus $next): OperationStatus
    {
        return $this->severity($next) > $this->severity($current) ? $next : $current;
    }

    private function severity(OperationStatus $status): int
    {
        return match ($status) {
            OperationStatus::Success => 0,
            OperationStatus::RequiresReview => 1,
            OperationStatus::Invalid => 2,
            OperationStatus::Blocked => 3,
            OperationStatus::Failed => 4,
        };
    }
}
