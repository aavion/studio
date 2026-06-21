<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Core\Log\MessageLoggerInterface;
use App\Core\Message\Message;
use App\Entity\SchedulerTask;
use App\Entity\SchedulerTaskRun;
use App\Scheduler\SchedulerMessageCode;
use App\Scheduler\SchedulerMessageKey;

final readonly class SchedulerRunReporter
{
    public function __construct(
        private MessageLoggerInterface $messageLogger,
        private SchedulerFailurePolicy $failurePolicy = new SchedulerFailurePolicy(),
        private SchedulerRunContextRedactor $contextRedactor = new SchedulerRunContextRedactor(),
    ) {
    }

    /**
     * @param list<array<string, mixed>> $results
     */
    public function logRunCompleted(?string $jobIdentifier, bool $force, int $durationMs, array $results): void
    {
        $this->messageLogger->log(Message::debug(
            SchedulerMessageCode::SCHEDULER_RUN_COMPLETED,
            SchedulerMessageKey::SCHEDULER_RUN_COMPLETED,
            context: [
                'job' => $jobIdentifier,
                'force' => $force,
                'task_count' => count($results),
                'duration_ms' => $durationMs,
                'tasks' => $results,
            ],
        ));
    }

    public function softBudgetMs(int $dueTaskCount): ?int
    {
        if ($dueTaskCount <= 0) {
            return null;
        }

        $limit = (int) ini_get('max_execution_time');

        return $limit > 0 ? max(1, (int) floor($limit * 1000 / $dueTaskCount)) : null;
    }

    public function logSoftBudgetIfExceeded(SchedulerTask $task, SchedulerTaskRun $run, ?int $softBudgetMs): void
    {
        $durationMs = $run->durationMs();

        if (null === $softBudgetMs || null === $durationMs || $durationMs <= $softBudgetMs) {
            return;
        }

        $this->messageLogger->log(Message::info(
            SchedulerMessageCode::SCHEDULER_TASK_SOFT_BUDGET_EXCEEDED,
            SchedulerMessageKey::SCHEDULER_TASK_SOFT_BUDGET_EXCEEDED,
            ['%task%' => $task->identifier()],
            [
                'task' => $task->identifier(),
                'run' => $run->uid(),
                'duration_ms' => $durationMs,
                'soft_budget_ms' => $softBudgetMs,
            ],
        ));
    }

    public function logInvalidCronDisabled(SchedulerTask $task, SchedulerTaskRun $run): void
    {
        $this->messageLogger->log(Message::exception(
            SchedulerMessageCode::SCHEDULER_TASK_INVALID_CRON_DISABLED,
            SchedulerMessageKey::SCHEDULER_TASK_INVALID_CRON_DISABLED,
            ['%task%' => $task->identifier()],
            [
                'task' => $task->identifier(),
                'run' => $run->uid(),
                'cron_expression' => $task->cronExpression(),
            ],
        ));
    }

    /**
     * @param list<Message> $messages
     */
    public function logTaskFailure(SchedulerTask $task, SchedulerTaskRun $run, array $messages): void
    {
        $message = $task->failureCount() >= $this->failurePolicy->disableAfterFailures()
            ? Message::exception(SchedulerMessageCode::SCHEDULER_TASK_DISABLED, SchedulerMessageKey::SCHEDULER_TASK_DISABLED, ['%task%' => $task->identifier()])
            : Message::warning(SchedulerMessageCode::SCHEDULER_TASK_FAILED, SchedulerMessageKey::SCHEDULER_TASK_FAILED, ['%task%' => $task->identifier()]);

        $this->messageLogger->log($message->withContext([
            'task' => $task->identifier(),
            'run' => $run->uid(),
            'failure_count' => $task->failureCount(),
            'messages' => array_map(fn (Message $message): array => $this->contextRedactor->redact($message->toArray()), $messages),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function taskResult(SchedulerTask $task, SchedulerTaskRun $run): array
    {
        return [
            'identifier' => $task->identifier(),
            'source' => $task->source(),
            'status' => $run->status()->value,
            'task_status' => $task->status()->value,
            'failure_count' => $task->failureCount(),
            'duration_ms' => $run->durationMs(),
            'next_due_at' => $task->nextDueAt()?->format(DATE_ATOM),
        ];
    }
}
