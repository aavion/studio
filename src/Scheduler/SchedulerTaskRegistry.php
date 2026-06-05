<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Core\Package\PackagePhpLoader;

final readonly class SchedulerTaskRegistry
{
    /**
     * @param iterable<SchedulerTaskProviderInterface> $providers
     */
    public function __construct(
        private iterable $providers,
        private ?PackagePhpLoader $packagePhpLoader = null,
    ) {
    }

    /**
     * @return array<string, SchedulerTaskDefinition>
     */
    public function definitions(): array
    {
        $this->packagePhpLoader?->loadActivePackages();

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
