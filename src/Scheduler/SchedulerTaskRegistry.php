<?php

declare(strict_types=1);

namespace App\Scheduler;

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
                    if ('system' === $definition->source() && 'system' !== $definitions[$definition->identifier()]->source()) {
                        $definitions[$definition->identifier()] = $definition;
                    }

                    continue;
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
