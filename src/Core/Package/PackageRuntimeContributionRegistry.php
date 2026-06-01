<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Package\Settings\PackageSettingDefinition;
use App\Core\Package\Settings\PackageSettingProviderInterface;
use App\Core\Package\Settings\PackageSettings;
use App\Core\Operation\ActionQueue;
use App\Entity\ExtensionPackage;
use App\Scheduler\SchedulerActionQueueProviderInterface;
use App\Scheduler\SchedulerCallableProviderInterface;
use App\Scheduler\SchedulerTaskDefinition;
use App\Scheduler\SchedulerTaskProviderInterface;
use App\View\Injection\ConfigurableStaticViewInjectionSet;
use App\View\Injection\DynamicViewInjection;
use App\View\Injection\DynamicViewInjectionProviderInterface;
use App\View\Injection\StaticViewInjection;
use App\View\Injection\StaticViewInjectionProviderInterface;
use InvalidArgumentException;

final class PackageRuntimeContributionRegistry implements StaticViewInjectionProviderInterface, DynamicViewInjectionProviderInterface, PackageSettingProviderInterface, SchedulerTaskProviderInterface, SchedulerCallableProviderInterface, SchedulerActionQueueProviderInterface
{
    public function __construct(private ?PackageSettings $packageSettingsStore = null)
    {
    }

    /**
     * @var list<StaticViewInjection>
     */
    private array $staticViewInjections = [];

    /**
     * @var list<ConfigurableStaticViewInjectionSet>
     */
    private array $configurableStaticViewInjectionSets = [];

    /**
     * @var list<DynamicViewInjection>
     */
    private array $dynamicViewInjections = [];

    /**
     * @var list<PackageSettingDefinition>
     */
    private array $packageSettingDefinitions = [];

    /**
     * @var list<SchedulerTaskDefinition>
     */
    private array $schedulerTaskDefinitions = [];

    /**
     * @var list<SchedulerCallableProviderInterface>
     */
    private array $schedulerCallableProviders = [];

    /**
     * @var list<SchedulerActionQueueProviderInterface>
     */
    private array $schedulerActionQueueProviders = [];

    public function add(ExtensionPackage $package, mixed $contribution): void
    {
        $staged = clone $this;
        $staged->addToRegistry($package, $contribution);
        $this->replaceWith($staged);
    }

    private function addToRegistry(ExtensionPackage $package, mixed $contribution): void
    {
        if (null === $contribution) {
            return;
        }

        if ($contribution instanceof StaticViewInjection) {
            $this->staticViewInjections[] = $contribution;

            return;
        }

        if ($contribution instanceof ConfigurableStaticViewInjectionSet) {
            $this->configurableStaticViewInjectionSets[] = $contribution;

            return;
        }

        if ($contribution instanceof DynamicViewInjection) {
            $this->dynamicViewInjections[] = $contribution;

            return;
        }

        if ($contribution instanceof PackageSettingDefinition) {
            $this->packageSettingDefinitions[] = $contribution;

            return;
        }

        if ($contribution instanceof SchedulerTaskDefinition) {
            $this->addSchedulerTaskDefinition($package, $contribution);

            return;
        }

        $providerHandled = false;

        if ($contribution instanceof StaticViewInjectionProviderInterface) {
            foreach ($contribution->staticViewInjections() as $injection) {
                $this->addToRegistry($package, $injection);
            }

            $providerHandled = true;
        }

        if ($contribution instanceof DynamicViewInjectionProviderInterface) {
            foreach ($contribution->dynamicViewInjections() as $injection) {
                $this->addToRegistry($package, $injection);
            }

            $providerHandled = true;
        }

        if ($contribution instanceof PackageSettingProviderInterface) {
            foreach ($contribution->packageSettings() as $definition) {
                $this->addToRegistry($package, $definition);
            }

            $providerHandled = true;
        }

        if ($contribution instanceof SchedulerTaskProviderInterface) {
            foreach ($contribution->schedulerTasks() as $definition) {
                $this->addToRegistry($package, $definition);
            }

            $providerHandled = true;
        }

        if ($contribution instanceof SchedulerCallableProviderInterface) {
            $this->schedulerCallableProviders[] = $contribution;
            $providerHandled = true;
        }

        if ($contribution instanceof SchedulerActionQueueProviderInterface) {
            $this->schedulerActionQueueProviders[] = $contribution;
            $providerHandled = true;
        }

        if ($providerHandled) {
            return;
        }

        if (is_iterable($contribution)) {
            foreach ($contribution as $item) {
                $this->addToRegistry($package, $item);
            }

            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Unsupported runtime contribution returned by package "%s".',
            $package->packageName(),
        ));
    }

    private function replaceWith(self $registry): void
    {
        $this->staticViewInjections = $registry->staticViewInjections;
        $this->configurableStaticViewInjectionSets = $registry->configurableStaticViewInjectionSets;
        $this->dynamicViewInjections = $registry->dynamicViewInjections;
        $this->packageSettingDefinitions = $registry->packageSettingDefinitions;
        $this->schedulerTaskDefinitions = $registry->schedulerTaskDefinitions;
        $this->schedulerCallableProviders = $registry->schedulerCallableProviders;
        $this->schedulerActionQueueProviders = $registry->schedulerActionQueueProviders;
    }

    private function addSchedulerTaskDefinition(ExtensionPackage $package, SchedulerTaskDefinition $definition): void
    {
        if ($definition->source() !== $package->packageName()) {
            throw new InvalidArgumentException(sprintf(
                'Scheduler task "%s" returned by package "%s" must use the package name as source.',
                $definition->identifier(),
                $package->packageName(),
            ));
        }

        if ($definition->trusted()) {
            throw new InvalidArgumentException(sprintf(
                'Scheduler task "%s" returned by package "%s" must not be trusted.',
                $definition->identifier(),
                $package->packageName(),
            ));
        }

        $this->schedulerTaskDefinitions[] = $definition;
    }

    public function staticViewInjections(): array
    {
        $injections = $this->staticViewInjections;

        foreach ($this->configurableStaticViewInjectionSets as $set) {
            $configuredBaseSlug = $this->packageSettingsStore?->get(
                $set->packageName(),
                $set->configKey(),
                $set->defaultBaseSlug(),
            ) ?? $set->defaultBaseSlug();
            array_push($injections, ...$set->staticViewInjections($configuredBaseSlug));
        }

        return $injections;
    }

    public function dynamicViewInjections(): array
    {
        return $this->dynamicViewInjections;
    }

    public function packageSettings(): array
    {
        return $this->packageSettingDefinitions;
    }

    public function schedulerTasks(): array
    {
        return $this->schedulerTaskDefinitions;
    }

    public function schedulerCallable(string $target): ?callable
    {
        foreach ($this->schedulerCallableProviders as $provider) {
            $callable = $provider->schedulerCallable($target);
            if (null !== $callable) {
                return $callable;
            }
        }

        return null;
    }

    public function schedulerActionQueue(string $target): ?ActionQueue
    {
        foreach ($this->schedulerActionQueueProviders as $provider) {
            $queue = $provider->schedulerActionQueue($target);
            if (null !== $queue) {
                return $queue;
            }
        }

        return null;
    }
}
