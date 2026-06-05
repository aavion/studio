<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Core\Id\UuidFactory;
use App\Core\Log\MessageLoggerInterface;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Package\ActivePackageProviderInterface;
use App\Entity\SchedulerTask;
use App\Entity\SchedulerTaskRun;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

final readonly class SchedulerRunner
{
    private const DISABLE_AFTER_FAILURES = 3;

    /**
     * @param iterable<SchedulerTaskExecutorInterface> $executors
     */
    public function __construct(
        private SchedulerSettings $settings,
        private SchedulerTaskSynchronizer $synchronizer,
        private EntityManagerInterface $entityManager,
        private iterable $executors,
        private SchedulerLockFactory $lockFactory,
        private UuidFactory $uuidFactory,
        private MessageLoggerInterface $messageLogger,
        private ActivePackageProviderInterface $activePackageProvider,
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
            $dueTasks = $this->dueTasks($tasks, $now, $jobIdentifier, $force);
            $results = $this->skippedTaskResults($tasks, $dueTasks, $jobIdentifier, $force);
            $softBudgetMs = $this->softBudgetMs(count($dueTasks));

            foreach ($dueTasks as $task) {
                if (!$this->isRunnable($task)) {
                    $results[] = $this->skippedTaskResult($task, 'not_runnable');

                    continue;
                }

                $results[] = $this->runTask($task, $softBudgetMs);
            }

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->messageLogger->log(Message::debug(
                MessageCode::SCHEDULER_RUN_COMPLETED,
                MessageKey::SCHEDULER_RUN_COMPLETED,
                context: [
                    'job' => $jobIdentifier,
                    'force' => $force,
                    'task_count' => count($results),
                    'duration_ms' => $durationMs,
                    'tasks' => $results,
                ],
            ));

            return new SchedulerRunResult('completed', $results, [
                'job' => $jobIdentifier,
                'force' => $force,
                'duration_ms' => $durationMs,
            ]);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param list<SchedulerTask> $tasks
     *
     * @return list<SchedulerTask>
     */
    private function dueTasks(array $tasks, DateTimeImmutable $now, ?string $jobIdentifier, bool $force): array
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

    private function isRunnable(SchedulerTask $task): bool
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
     * @return array<string, mixed>
     */
    private function runTask(SchedulerTask $task, ?int $softBudgetMs): array
    {
        $startedAt = new DateTimeImmutable();
        $run = new SchedulerTaskRun($this->uuidFactory->generate(), $task, $startedAt, [
            'task' => $task->identifier(),
            'source' => $task->source(),
            'type' => $task->type()->value,
        ]);
        $task->markAttempt($startedAt);
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        if (!SchedulerCron::isValid($task->cronExpression())) {
            $finishedAt = new DateTimeImmutable();
            $task->markFailure($finishedAt, 1);
            $run->finish(SchedulerTaskRunStatus::Failed, $finishedAt, [
                'reason' => 'invalid_cron',
                'cron_expression' => $task->cronExpression(),
            ]);
            $this->entityManager->flush();
            $this->logInvalidCronDisabled($task, $run);

            return $this->taskResult($task, $run);
        }

        try {
            $executor = $this->executorFor($task);
            $execution = $executor->execute($task);
            $executionContext = $this->validatedContext($execution->context());
            $finishedAt = new DateTimeImmutable();

            if ($execution->isSuccess()) {
                $nextRun = SchedulerCron::nextRun($task->cronExpression(), $finishedAt);
                $task->markSuccess($finishedAt, $nextRun);
                $run->finish(SchedulerTaskRunStatus::Success, $finishedAt, $executionContext);
            } else {
                $task->markFailure($finishedAt, self::DISABLE_AFTER_FAILURES);
                $run->finish(SchedulerTaskRunStatus::Failed, $finishedAt, $executionContext);
                $this->logTaskFailure($task, $run, $execution->messages());
            }

            $this->entityManager->flush();
            $this->logSoftBudgetIfExceeded($task, $run, $softBudgetMs);

            return $this->taskResult($task, $run);
        } catch (Throwable $error) {
            $finishedAt = new DateTimeImmutable();
            $task->markFailure($finishedAt, self::DISABLE_AFTER_FAILURES);
            $run->finish(SchedulerTaskRunStatus::Failed, $finishedAt, [
                'exception' => $error::class,
                'message' => $error->getMessage(),
            ], true);
            $this->entityManager->flush();
            $this->logSoftBudgetIfExceeded($task, $run, $softBudgetMs);
            $this->logTaskFailure($task, $run, [
                Message::exception(MessageCode::SCHEDULER_TASK_FAILED, MessageKey::SCHEDULER_TASK_FAILED, [
                    '%task%' => $task->identifier(),
                ], [
                    'task' => $task->identifier(),
                    'exception' => $error::class,
                    'message' => $error->getMessage(),
                ]),
            ]);

            return $this->taskResult($task, $run);
        }
    }

    private function executorFor(SchedulerTask $task): SchedulerTaskExecutorInterface
    {
        foreach ($this->executors as $executor) {
            if ($executor->supports($task)) {
                return $executor;
            }
        }

        throw new \RuntimeException(sprintf('No scheduler executor supports task "%s".', $task->identifier()));
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function validatedContext(array $context): array
    {
        try {
            json_encode($context, JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            throw new \InvalidArgumentException('Scheduler task context must be JSON-encodable.', previous: $error);
        }

        return $context;
    }

    /**
     * @param list<SchedulerTask> $tasks
     * @param list<SchedulerTask> $dueTasks
     *
     * @return list<array<string, mixed>>
     */
    private function skippedTaskResults(array $tasks, array $dueTasks, ?string $jobIdentifier, bool $force): array
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
    private function skippedTaskResult(SchedulerTask $task, ?string $reason = null): array
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

    private function softBudgetMs(int $dueTaskCount): ?int
    {
        if ($dueTaskCount <= 0) {
            return null;
        }

        $limit = (int) ini_get('max_execution_time');

        return $limit > 0 ? max(1, (int) floor($limit * 1000 / $dueTaskCount)) : null;
    }

    private function logSoftBudgetIfExceeded(SchedulerTask $task, SchedulerTaskRun $run, ?int $softBudgetMs): void
    {
        $durationMs = $run->durationMs();

        if (null === $softBudgetMs || null === $durationMs || $durationMs <= $softBudgetMs) {
            return;
        }

        $this->messageLogger->log(Message::info(
            MessageCode::SCHEDULER_TASK_SOFT_BUDGET_EXCEEDED,
            MessageKey::SCHEDULER_TASK_SOFT_BUDGET_EXCEEDED,
            ['%task%' => $task->identifier()],
            [
                'task' => $task->identifier(),
                'run' => $run->uid(),
                'duration_ms' => $durationMs,
                'soft_budget_ms' => $softBudgetMs,
            ],
        ));
    }

    private function logInvalidCronDisabled(SchedulerTask $task, SchedulerTaskRun $run): void
    {
        $this->messageLogger->log(Message::exception(
            MessageCode::SCHEDULER_TASK_INVALID_CRON_DISABLED,
            MessageKey::SCHEDULER_TASK_INVALID_CRON_DISABLED,
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
    private function logTaskFailure(SchedulerTask $task, SchedulerTaskRun $run, array $messages): void
    {
        $message = $task->failureCount() >= self::DISABLE_AFTER_FAILURES
            ? Message::exception(MessageCode::SCHEDULER_TASK_DISABLED, MessageKey::SCHEDULER_TASK_DISABLED, ['%task%' => $task->identifier()])
            : Message::warning(MessageCode::SCHEDULER_TASK_FAILED, MessageKey::SCHEDULER_TASK_FAILED, ['%task%' => $task->identifier()]);

        $this->messageLogger->log($message->withContext([
            'task' => $task->identifier(),
            'run' => $run->uid(),
            'failure_count' => $task->failureCount(),
            'messages' => array_map(static fn (Message $message): array => $message->toArray(), $messages),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function taskResult(SchedulerTask $task, SchedulerTaskRun $run): array
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
