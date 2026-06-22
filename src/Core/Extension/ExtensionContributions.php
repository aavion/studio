<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointHandlerInterface;
use App\Api\Endpoint\ApiEndpointHandlerProviderInterface;
use App\Api\Endpoint\ApiEndpointProviderInterface;
use App\Core\Extension\Content\ExtensionContentSchemaDefinition;
use App\Core\Extension\Content\ExtensionContentSchemaProviderInterface;
use App\Core\Extension\Database\ExtensionDatabaseProviderInterface;
use App\Core\Extension\Database\ExtensionDatabaseTable;
use App\Core\Extension\Settings\ExtensionSettingDefinition;
use App\Core\Extension\Settings\ExtensionSettingProviderInterface;
use App\Core\Event\PublicEventInterface;
use App\Live\LiveEndpointDefinition;
use App\Live\LiveEndpointHandlerInterface;
use App\Live\LiveEndpointHandlerProviderInterface;
use App\Live\LiveEndpointProviderInterface;
use App\Privacy\Cookie\CookieConsentDefinition;
use App\Privacy\Cookie\CookieConsentProviderInterface;
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
final class ExtensionContributions implements \IteratorAggregate
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
        StaticViewInjection|ConfigurableStaticViewInjectionSet|DynamicViewInjection|ExtensionSettingDefinition|SchedulerTaskDefinition|ApiEndpointDefinition|ApiEndpointHandlerInterface|LiveEndpointDefinition|LiveEndpointHandlerInterface|CookieConsentDefinition|ExtensionDatabaseTable|ExtensionContentSchemaDefinition|ExtensionOperationDefinition|ExtensionRuntimeContributionFactory|ExtensionActivationContributionFactory|ExtensionRuntimeBoot|ExtensionEventListenerContribution|ExtensionProviderContribution|StaticViewInjectionProviderInterface|DynamicViewInjectionProviderInterface|ExtensionSettingProviderInterface|ApiEndpointProviderInterface|ApiEndpointHandlerProviderInterface|LiveEndpointProviderInterface|LiveEndpointHandlerProviderInterface|CookieConsentProviderInterface|SchedulerTaskProviderInterface|SchedulerCallableProviderInterface|SchedulerActionQueueProviderInterface|ExtensionActionQueueProviderInterface|ExtensionDatabaseProviderInterface|ExtensionContentSchemaProviderInterface $contribution,
    ): self {
        $this->items[] = $contribution;

        return $this;
    }

    public function runtime(callable $factory): self
    {
        return $this->add(new ExtensionRuntimeContributionFactory($factory));
    }

    public function activation(callable $factory): self
    {
        return $this->add(new ExtensionActivationContributionFactory($factory));
    }

    public function runtimeBoot(callable $boot): self
    {
        return $this->add(new ExtensionRuntimeBoot($boot));
    }

    /**
     * @param class-string<PublicEventInterface> $eventClass
     */
    public function eventListener(string $eventClass, callable $listener, int $priority = 0): self
    {
        return $this->add(new ExtensionEventListenerContribution($eventClass, $listener, $priority));
    }

    public function provider(ExtensionScope $scope, callable $provider): self
    {
        return $this->add(new ExtensionProviderContribution($scope, $provider));
    }

    public function captchaProvider(callable $provider): self
    {
        return $this->provider(ExtensionScope::CaptchaProvider, $provider);
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

    public function setting(ExtensionSettingDefinition $definition): self
    {
        return $this->add($definition);
    }

    public function schedulerTask(SchedulerTaskDefinition $definition): self
    {
        return $this->add($definition);
    }

    public function apiEndpoint(ApiEndpointDefinition $definition): self
    {
        return $this->add($definition);
    }

    public function apiEndpointHandler(ApiEndpointHandlerInterface $handler): self
    {
        return $this->add($handler);
    }

    public function liveEndpoint(LiveEndpointDefinition $definition): self
    {
        return $this->add($definition);
    }

    public function liveEndpointHandler(LiveEndpointHandlerInterface $handler): self
    {
        return $this->add($handler);
    }

    public function cookie(CookieConsentDefinition $definition): self
    {
        return $this->add($definition);
    }

    public function databaseTable(ExtensionDatabaseTable $table): self
    {
        return $this->add($table);
    }

    public function contentSchema(ExtensionContentSchemaDefinition $definition): self
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

    public function settingsProvider(ExtensionSettingProviderInterface $provider): self
    {
        return $this->add($provider);
    }

    public function apiEndpointProvider(ApiEndpointProviderInterface $provider): self
    {
        return $this->add($provider);
    }

    public function apiEndpointHandlerProvider(ApiEndpointHandlerProviderInterface $provider): self
    {
        return $this->add($provider);
    }

    public function liveEndpointProvider(LiveEndpointProviderInterface $provider): self
    {
        return $this->add($provider);
    }

    public function liveEndpointHandlerProvider(LiveEndpointHandlerProviderInterface $provider): self
    {
        return $this->add($provider);
    }

    public function cookieProvider(CookieConsentProviderInterface $provider): self
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

    public function operation(ExtensionOperationDefinition $definition): self
    {
        return $this->add($definition);
    }

    public function actionQueueProvider(ExtensionActionQueueProviderInterface $provider): self
    {
        return $this->add($provider);
    }

    public function databaseProvider(ExtensionDatabaseProviderInterface $provider): self
    {
        return $this->add($provider);
    }

    public function contentSchemaProvider(ExtensionContentSchemaProviderInterface $provider): self
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
