<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Core\Package\ActivePackageProviderInterface;
use App\Entity\SchedulerTask;
use DateTimeImmutable;

final readonly class SchedulerDueTaskSelector
{
    public function __construct(
        private SchedulerSettings $settings,
        private ActivePackageProviderInterface $activePackageProvider,
    ) {
    }

    /**
     * @param list<SchedulerTask> $tasks
     *
     * @return list<SchedulerTask>
     */
    public function dueTasks(array $tasks, DateTimeImmutable $now, ?string $jobIdentifier, bool $force): array
    {
        $due = [];

        foreach ($tasks as $task) {
            if (null !== $jobIdentifier && $task->identifier() !== $jobIdentifier) {
                continue;
            }

            if (!$force && !$this->isRunnable($task)) {
                continue;
            }

            if ($force || null === $task->nextDueAt() || $task->nextDueAt() <= $now) {
                $due[] = $task;
            }
        }

        usort($due, static fn (SchedulerTask $left, SchedulerTask $right): int => ($left->nextDueAt()?->getTimestamp() ?? 0) <=> ($right->nextDueAt()?->getTimestamp() ?? 0));

        return $due;
    }

    public function isRunnable(SchedulerTask $task): bool
    {
        if (SchedulerTaskStatus::Active !== $task->status()) {
            return false;
        }

        if ('system' === $task->source()) {
            return true;
        }

        if (null === $this->activePackageProvider->package($task->source())) {
            return false;
        }

        return SchedulerTaskType::ActionQueue !== $task->type() || $this->settings->packageActionQueuesEnabled();
    }

    /**
     * @param list<SchedulerTask> $tasks
     * @param list<SchedulerTask> $dueTasks
     *
     * @return list<array<string, mixed>>
     */
    public function skippedTaskResults(array $tasks, array $dueTasks, ?string $jobIdentifier, bool $force): array
    {
        if ($force) {
            return [];
        }

        $dueIdentifiers = array_fill_keys(array_map(static fn (SchedulerTask $task): string => $task->identifier(), $dueTasks), true);
        $results = [];

        foreach ($tasks as $task) {
            if (null !== $jobIdentifier && $task->identifier() !== $jobIdentifier) {
                continue;
            }

            if (!$this->isRunnable($task) || isset($dueIdentifiers[$task->identifier()])) {
                continue;
            }

            $results[] = $this->skippedTaskResult($task);
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    public function skippedTaskResult(SchedulerTask $task, ?string $reason = null): array
    {
        return [
            'identifier' => $task->identifier(),
            'source' => $task->source(),
            'status' => SchedulerTaskRunStatus::Skipped->value,
            'task_status' => $task->status()->value,
            'failure_count' => $task->failureCount(),
            'duration_ms' => null,
            'next_due_at' => $task->nextDueAt()?->format(DATE_ATOM),
            ...($reason ? ['reason' => $reason] : []),
        ];
    }
}
