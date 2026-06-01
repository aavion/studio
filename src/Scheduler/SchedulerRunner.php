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
            $tasks = $this->synchronizer->synchronize();
            $dueTasks = $this->dueTasks($tasks, $now, $jobIdentifier, $force);
            $results = [];

            foreach ($dueTasks as $task) {
                $results[] = $this->runTask($task, $now);
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

            if (!$this->isRunnable($task)) {
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
    private function runTask(SchedulerTask $task, DateTimeImmutable $now): array
    {
        $run = new SchedulerTaskRun($this->uuidFactory->v4(), $task, $now, [
            'task' => $task->identifier(),
            'source' => $task->source(),
            'type' => $task->type()->value,
        ]);
        $task->markAttempt($now);
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        try {
            $executor = $this->executorFor($task);
            $execution = $executor->execute($task);
            $finishedAt = new DateTimeImmutable();

            if ($execution->isSuccess()) {
                $nextRun = SchedulerCron::nextRun($task->cronExpression(), $finishedAt);
                $task->markSuccess($finishedAt, $nextRun);
                $run->finish(SchedulerTaskRunStatus::Success, $finishedAt, $execution->context());
            } else {
                $task->markFailure($finishedAt, self::DISABLE_AFTER_FAILURES);
                $run->finish(SchedulerTaskRunStatus::Failed, $finishedAt, $execution->context());
                $this->logTaskFailure($task, $run, $execution->messages());
            }

            $this->entityManager->flush();

            return $this->taskResult($task, $run);
        } catch (Throwable $error) {
            $finishedAt = new DateTimeImmutable();
            $task->markFailure($finishedAt, self::DISABLE_AFTER_FAILURES);
            $run->finish(SchedulerTaskRunStatus::Failed, $finishedAt, [
                'exception' => $error::class,
                'message' => $error->getMessage(),
            ]);
            $this->entityManager->flush();
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
