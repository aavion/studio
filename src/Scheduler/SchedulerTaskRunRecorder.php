<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Core\Id\UuidFactory;
use App\Core\Message\Message;
use App\Entity\SchedulerTask;
use App\Entity\SchedulerTaskRun;
use App\Scheduler\SchedulerMessageCode;
use App\Scheduler\SchedulerMessageKey;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

final readonly class SchedulerTaskRunRecorder
{
    /**
     * @param iterable<SchedulerTaskExecutorInterface> $executors
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private iterable $executors,
        private UuidFactory $uuidFactory,
        private SchedulerRunReporter $reporter,
        private SchedulerFailurePolicy $failurePolicy = new SchedulerFailurePolicy(),
        private SchedulerRunContextRedactor $contextRedactor = new SchedulerRunContextRedactor(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function runTask(SchedulerTask $task, ?int $softBudgetMs): array
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
            $task->markFailure($finishedAt, $this->failurePolicy->invalidCronDisableAfterFailures());
            $run->finish(SchedulerTaskRunStatus::Failed, $finishedAt, [
                'reason' => 'invalid_cron',
                'cron_expression' => $task->cronExpression(),
            ]);
            $this->entityManager->flush();
            $this->reporter->logInvalidCronDisabled($task, $run);

            return $this->reporter->taskResult($task, $run);
        }

        try {
            $executor = $this->executorFor($task);
            $execution = $executor->execute($task);
            $executionContext = $this->validatedContext($this->contextRedactor->redact($execution->context()));
            $finishedAt = new DateTimeImmutable();

            if ($execution->isSuccess()) {
                $nextRun = SchedulerCron::nextRun($task->cronExpression(), $finishedAt);
                $task->markSuccess($finishedAt, $nextRun);
                $run->finish(SchedulerTaskRunStatus::Success, $finishedAt, $executionContext);
            } else {
                $task->markFailure($finishedAt, $this->failurePolicy->disableAfterFailures());
                $run->finish(SchedulerTaskRunStatus::Failed, $finishedAt, $executionContext);
                $this->reporter->logTaskFailure($task, $run, $execution->messages());
            }

            $this->entityManager->flush();
            $this->reporter->logSoftBudgetIfExceeded($task, $run, $softBudgetMs);

            return $this->reporter->taskResult($task, $run);
        } catch (Throwable $error) {
            $finishedAt = new DateTimeImmutable();
            $task->markFailure($finishedAt, $this->failurePolicy->disableAfterFailures());
            $run->finish(SchedulerTaskRunStatus::Failed, $finishedAt, $this->contextRedactor->redact([
                'exception' => $error::class,
                'message' => $error->getMessage(),
            ]), true);
            $this->entityManager->flush();
            $this->reporter->logSoftBudgetIfExceeded($task, $run, $softBudgetMs);
            $this->reporter->logTaskFailure($task, $run, [
                Message::exception(SchedulerMessageCode::SCHEDULER_TASK_FAILED, SchedulerMessageKey::SCHEDULER_TASK_FAILED, [
                    '%task%' => $task->identifier(),
                ], [
                    'task' => $task->identifier(),
                    'exception' => $error::class,
                    'message' => $error->getMessage(),
                ]),
            ]);

            return $this->reporter->taskResult($task, $run);
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
}
