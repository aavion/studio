<?php

declare(strict_types=1);

namespace App\Core\Extension\Contribution;

use App\Core\Operation\ActionQueue;
use App\Entity\Extension;
use App\Scheduler\SchedulerActionQueueProviderInterface;
use App\Scheduler\SchedulerCallableProviderInterface;
use App\Scheduler\SchedulerTaskDefinition;
use App\Scheduler\SchedulerTaskProviderInterface;

final class ExtensionRuntimeSchedulerContributions implements SchedulerTaskProviderInterface, SchedulerCallableProviderInterface, SchedulerActionQueueProviderInterface
{
    private array $taskDefinitions = [];

    private array $callableProviders = [];

    private array $actionQueueProviders = [];

    public function addTask(Extension $extension, SchedulerTaskDefinition $definition, ExtensionRuntimeContributionGuard $guard): void
    {
        $guard->assertSchedulerTask($extension, $definition);
        $this->taskDefinitions[] = $definition;
    }

    public function addCallableProvider(SchedulerCallableProviderInterface $provider): void
    {
        $this->callableProviders[] = $provider;
    }

    public function addActionQueueProvider(SchedulerActionQueueProviderInterface $provider): void
    {
        $this->actionQueueProviders[] = $provider;
    }

    public function schedulerTasks(): array
    {
        return $this->taskDefinitions;
    }

    public function schedulerCallable(string $target): ?callable
    {
        foreach ($this->callableProviders as $provider) {
            $callable = $provider->schedulerCallable($target);
            if (null !== $callable) {
                return $callable;
            }
        }

        return null;
    }

    public function schedulerActionQueue(string $target): ?ActionQueue
    {
        foreach ($this->actionQueueProviders as $provider) {
            $queue = $provider->schedulerActionQueue($target);
            if (null !== $queue) {
                return $queue;
            }
        }

        return null;
    }
}
