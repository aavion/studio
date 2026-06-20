<?php

declare(strict_types=1);

namespace App\Core\Extension\Contribution;

use App\Core\Extension\ExtensionMessageKey;
use App\Core\Message\MessageException;
use App\Core\Operation\ActionQueue;
use App\Entity\Extension;
use App\Scheduler\SchedulerActionQueueProviderInterface;
use App\Scheduler\SchedulerCallableProviderInterface;
use App\Scheduler\SchedulerTaskDefinition;
use App\Scheduler\SchedulerTaskProviderInterface;

final class ExtensionRuntimeSchedulerContributions implements SchedulerTaskProviderInterface, SchedulerCallableProviderInterface, SchedulerActionQueueProviderInterface
{
    private array $taskDefinitions = [];

    /**
     * @var list<array{extension: string, provider: SchedulerCallableProviderInterface}>
     */
    private array $callableProviders = [];

    /**
     * @var list<array{extension: string, provider: SchedulerActionQueueProviderInterface}>
     */
    private array $actionQueueProviders = [];

    public function addTask(Extension $extension, SchedulerTaskDefinition $definition, ExtensionRuntimeContributionGuard $guard): void
    {
        $guard->assertSchedulerTask($extension, $definition);
        foreach ($this->taskDefinitions as $existing) {
            if ($existing->identifier() === $definition->identifier()) {
                throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_RUNTIME_CONTRIBUTION_UNSUPPORTED, [
                    '%extension%' => $extension->extensionName(),
                    '%type%' => SchedulerTaskDefinition::class.'('.$definition->identifier().') duplicate_identifier',
                ], [
                    'extension' => $extension->extensionName(),
                    'identifier' => $definition->identifier(),
                ]);
            }
        }

        $this->taskDefinitions[] = $definition;
    }

    public function addCallableProvider(Extension $extension, SchedulerCallableProviderInterface $provider): void
    {
        $this->callableProviders[] = ['extension' => $extension->extensionName(), 'provider' => $provider];
    }

    public function addActionQueueProvider(Extension $extension, SchedulerActionQueueProviderInterface $provider): void
    {
        $this->actionQueueProviders[] = ['extension' => $extension->extensionName(), 'provider' => $provider];
    }

    public function schedulerTasks(): array
    {
        return $this->taskDefinitions;
    }

    public function schedulerCallable(string $target): ?callable
    {
        foreach ($this->callableProviders as $entry) {
            if (!str_starts_with($target, $entry['extension'].'.')) {
                continue;
            }

            $callable = $entry['provider']->schedulerCallable($target);
            if (null !== $callable) {
                return $callable;
            }
        }

        return null;
    }

    public function schedulerActionQueue(string $target): ?ActionQueue
    {
        foreach ($this->actionQueueProviders as $entry) {
            if (!str_starts_with($target, $entry['extension'].'.')) {
                continue;
            }

            $queue = $entry['provider']->schedulerActionQueue($target);
            if (null !== $queue) {
                return $queue;
            }
        }

        return null;
    }
}
