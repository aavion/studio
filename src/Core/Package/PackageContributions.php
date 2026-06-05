<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Package\Settings\PackageSettingDefinition;
use App\Core\Package\Settings\PackageSettingProviderInterface;
use App\Scheduler\SchedulerActionQueueProviderInterface;
use App\Scheduler\SchedulerCallableProviderInterface;
use App\Scheduler\SchedulerTaskDefinition;
use App\Scheduler\SchedulerTaskProviderInterface;
use App\View\Injection\ConfigurableStaticViewInjectionSet;
use App\View\Injection\DynamicViewInjection;
use App\View\Injection\DynamicViewInjectionProviderInterface;
use App\View\Injection\StaticViewInjection;
use App\View\Injection\StaticViewInjectionProviderInterface;
use ArrayIterator;
use Traversable;

/**
 * @implements \IteratorAggregate<int, object>
 */
final class PackageContributions implements \IteratorAggregate
{
    /**
     * @var list<object>
     */
    private array $items = [];

    public static function create(): self
    {
        return new self();
    }

    public function add(
        StaticViewInjection|ConfigurableStaticViewInjectionSet|DynamicViewInjection|PackageSettingDefinition|SchedulerTaskDefinition|StaticViewInjectionProviderInterface|DynamicViewInjectionProviderInterface|PackageSettingProviderInterface|SchedulerTaskProviderInterface|SchedulerCallableProviderInterface|SchedulerActionQueueProviderInterface $contribution,
    ): self {
        $this->items[] = $contribution;

        return $this;
    }

    public function staticView(StaticViewInjection $injection): self
    {
        return $this->add($injection);
    }

    public function configurableStaticViews(ConfigurableStaticViewInjectionSet $set): self
    {
        return $this->add($set);
    }

    public function dynamicView(DynamicViewInjection $injection): self
    {
        return $this->add($injection);
    }

    public function setting(PackageSettingDefinition $definition): self
    {
        return $this->add($definition);
    }

    public function schedulerTask(SchedulerTaskDefinition $definition): self
    {
        return $this->add($definition);
    }

    public function staticViewProvider(StaticViewInjectionProviderInterface $provider): self
    {
        return $this->add($provider);
    }

    public function dynamicViewProvider(DynamicViewInjectionProviderInterface $provider): self
    {
        return $this->add($provider);
    }

    public function settingsProvider(PackageSettingProviderInterface $provider): self
    {
        return $this->add($provider);
    }

    public function schedulerTaskProvider(SchedulerTaskProviderInterface $provider): self
    {
        return $this->add($provider);
    }

    public function schedulerCallableProvider(SchedulerCallableProviderInterface $provider): self
    {
        return $this->add($provider);
    }

    public function schedulerActionQueueProvider(SchedulerActionQueueProviderInterface $provider): self
    {
        return $this->add($provider);
    }

    /**
     * @return Traversable<int, object>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }
}
