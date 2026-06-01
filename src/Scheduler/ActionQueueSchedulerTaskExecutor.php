<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Core\Operation\OperationExecutor;
use App\Entity\SchedulerTask;

final readonly class ActionQueueSchedulerTaskExecutor implements SchedulerTaskExecutorInterface
{
    /**
     * @param iterable<SchedulerActionQueueProviderInterface> $providers
     */
    public function __construct(
        private iterable $providers,
        private OperationExecutor $operationExecutor,
    ) {
    }

    public function supports(SchedulerTask $task): bool
    {
        return SchedulerTaskType::ActionQueue === $task->type();
    }

    public function execute(SchedulerTask $task): SchedulerTaskExecution
    {
        foreach ($this->providers as $provider) {
            $queue = $provider->schedulerActionQueue($task->target());

            if (null === $queue) {
                continue;
            }

            $execution = $this->operationExecutor->executeQueue($queue);
            $result = $execution->result();

            return $result->isSuccess()
                ? SchedulerTaskExecution::success($result->context(), $result->messages())
                : SchedulerTaskExecution::failed($result->context(), [
                    ...$result->issues(),
                    ...$result->messages(),
                ]);
        }

        return SchedulerTaskExecution::failed([
            'target' => $task->target(),
            'reason' => 'missing_action_queue',
        ]);
    }
}
