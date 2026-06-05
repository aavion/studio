<?php

declare(strict_types=1);

namespace App\Scheduler;

use DateTimeImmutable;

final readonly class SchedulerRunner
{
    public function __construct(
        private SchedulerSettings $settings,
        private SchedulerTaskSynchronizer $synchronizer,
        private SchedulerLockFactory $lockFactory,
        private SchedulerDueTaskSelector $taskSelector,
        private SchedulerTaskRunRecorder $taskRunRecorder,
        private SchedulerRunReporter $reporter,
    ) {
    }

    public function run(?string $jobIdentifier = null, bool $force = false): SchedulerRunResult
    {
        $startedAt = microtime(true);
        $now = new DateTimeImmutable();

        if (!$this->settings->enabled()) {
            return new SchedulerRunResult('disabled', context: ['reason' => 'scheduler_disabled']);
        }

        $lock = $this->lockFactory->acquire('run');
        if (null === $lock) {
            return new SchedulerRunResult('locked', context: ['job' => $jobIdentifier]);
        }

        try {
            $tasks = $this->synchronizer->synchronize($force ? $jobIdentifier : null);
            $dueTasks = $this->taskSelector->dueTasks($tasks, $now, $jobIdentifier, $force);
            $results = $this->taskSelector->skippedTaskResults($tasks, $dueTasks, $jobIdentifier, $force);
            $softBudgetMs = $this->reporter->softBudgetMs(count($dueTasks));

            foreach ($dueTasks as $task) {
                if (!$this->taskSelector->isRunnable($task)) {
                    $results[] = $this->taskSelector->skippedTaskResult($task, 'not_runnable');

                    continue;
                }

                $results[] = $this->taskRunRecorder->runTask($task, $softBudgetMs);
            }

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->reporter->logRunCompleted($jobIdentifier, $force, $durationMs, $results);

            return new SchedulerRunResult('completed', $results, [
                'job' => $jobIdentifier,
                'force' => $force,
                'duration_ms' => $durationMs,
            ]);
        } finally {
            $lock->release();
        }
    }
}
