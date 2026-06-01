<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Entity\SchedulerTask;
use Throwable;

final readonly class CallableSchedulerTaskExecutor implements SchedulerTaskExecutorInterface
{
    /**
     * @param iterable<SchedulerCallableProviderInterface> $providers
     */
    public function __construct(private iterable $providers)
    {
    }

    public function supports(SchedulerTask $task): bool
    {
        return SchedulerTaskType::Callable === $task->type();
    }

    public function execute(SchedulerTask $task): SchedulerTaskExecution
    {
        foreach ($this->providers as $provider) {
            $callable = $provider->schedulerCallable($task->target());

            if (null === $callable) {
                continue;
            }

            try {
                return $callable();
            } catch (Throwable $error) {
                return SchedulerTaskExecution::failed([
                    'target' => $task->target(),
                    'exception' => $error::class,
                    'message' => $error->getMessage(),
                ]);
            }
        }

        return SchedulerTaskExecution::failed([
            'target' => $task->target(),
            'reason' => 'missing_callable',
        ]);
    }
}
