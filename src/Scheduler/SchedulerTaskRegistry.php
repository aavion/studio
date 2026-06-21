<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Core\Extension\ExtensionPhpLoader;

final readonly class SchedulerTaskRegistry
{
    /**
     * @param iterable<SchedulerTaskProviderInterface> $providers
     */
    public function __construct(
        private iterable $providers,
        private ?ExtensionPhpLoader $extensionPhpLoader = null,
    ) {
    }

    /**
     * @return array<string, SchedulerTaskDefinition>
     */
    public function definitions(): array
    {
        $this->extensionPhpLoader?->loadActiveExtensions();

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
