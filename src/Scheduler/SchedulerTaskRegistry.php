<?php

declare(strict_types=1);

namespace App\Scheduler;

use InvalidArgumentException;

final readonly class SchedulerTaskRegistry
{
    /**
     * @param iterable<SchedulerTaskProviderInterface> $providers
     */
    public function __construct(private iterable $providers)
    {
    }

    /**
     * @return array<string, SchedulerTaskDefinition>
     */
    public function definitions(): array
    {
        $definitions = [];

        foreach ($this->providers as $provider) {
            foreach ($provider->schedulerTasks() as $definition) {
                if (isset($definitions[$definition->identifier()])) {
                    throw new InvalidArgumentException(sprintf('Duplicate scheduler task identifier "%s".', $definition->identifier()));
                }

                $definitions[$definition->identifier()] = $definition;
            }
        }

        ksort($definitions);

        return $definitions;
    }

    public function definition(string $identifier): ?SchedulerTaskDefinition
    {
        $definitions = $this->definitions();

        return $definitions[$identifier] ?? null;
    }
}
