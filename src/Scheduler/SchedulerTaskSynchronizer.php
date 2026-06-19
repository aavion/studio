<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Entity\SchedulerTask;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SchedulerTaskSynchronizer
{
    public function __construct(
        private SchedulerTaskRegistry $registry,
        private EntityManagerInterface $entityManager,
        private SchedulerSettings $settings,
    ) {
    }

    /**
     * @return list<SchedulerTask>
     */
    public function synchronize(?string $includeIdentifier = null): array
    {
        $now = new DateTimeImmutable();
        $tasks = [];

        foreach ($this->registry->definitions() as $definition) {
            if ($definition->identifier() !== $includeIdentifier && !$this->isVisibleDefinition($definition)) {
                continue;
            }

            $task = $this->entityManager->find(SchedulerTask::class, $definition->identifier());

            if (!$task instanceof SchedulerTask) {
                $task = new SchedulerTask($definition, $now);
                $this->entityManager->persist($task);
            } else {
                $task->syncDefinition($definition, $now);
            }

            $nextRun = SchedulerCron::nextRunOrNull($task->cronExpression(), $now);
            if (null !== $nextRun) {
                $task->seedNextDue($nextRun);
            }
            $tasks[] = $task;
        }

        $this->entityManager->flush();

        return $tasks;
    }

    private function isVisibleDefinition(SchedulerTaskDefinition $definition): bool
    {
        return $definition->trusted()
            || SchedulerTaskType::ActionQueue !== $definition->type()
            || $this->settings->extensionActionQueuesEnabled();
    }
}
