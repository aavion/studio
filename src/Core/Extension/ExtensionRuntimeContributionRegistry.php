<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointHandlerInterface;
use App\Api\Endpoint\ApiEndpointHandlerProviderInterface;
use App\Api\Endpoint\ApiEndpointProviderInterface;
use App\Core\Operation\ActionQueue;
use App\Core\Extension\Content\ExtensionContentSchemaDefinition;
use App\Core\Extension\Content\ExtensionContentSchemaProviderInterface;
use App\Core\Extension\Contribution\ExtensionRuntimeContributionExpander;
use App\Core\Extension\Contribution\ExtensionRuntimeContributionGuard;
use App\Core\Extension\Contribution\ExtensionRuntimeEndpointContributions;
use App\Core\Extension\Contribution\ExtensionRuntimeSchedulerContributions;
use App\Core\Extension\Contribution\ExtensionRuntimeViewContributions;
use App\Core\Extension\Database\ExtensionDatabaseProviderInterface;
use App\Core\Extension\Database\ExtensionDatabaseTable;
use App\Core\Extension\Settings\ExtensionSettingDefinition;
use App\Core\Extension\Settings\ExtensionSettingProviderInterface;
use App\Core\Extension\Settings\ExtensionSettings;
use App\Entity\Extension;
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

final class ExtensionRuntimeContributionRegistry implements StaticViewInjectionProviderInterface, DynamicViewInjectionProviderInterface, ExtensionSettingProviderInterface, ApiEndpointProviderInterface, ApiEndpointHandlerProviderInterface, LiveEndpointProviderInterface, LiveEndpointHandlerProviderInterface, CookieConsentProviderInterface, SchedulerTaskProviderInterface, SchedulerCallableProviderInterface, SchedulerActionQueueProviderInterface, ExtensionDatabaseProviderInterface, ExtensionContentSchemaProviderInterface
{
    public function __construct(
        ?ExtensionSettings $extensionSettingsStore = null,
        private ?ExtensionRuntimeContributionExpander $contributionExpander = null,
        private ?ExtensionRuntimeContributionGuard $contributionGuard = null,
    ) {
        $this->viewContributions = new ExtensionRuntimeViewContributions($extensionSettingsStore);
        $this->endpointContributions = new ExtensionRuntimeEndpointContributions();
        $this->schedulerContributions = new ExtensionRuntimeSchedulerContributions();
    }

    private ExtensionRuntimeViewContributions $viewContributions;

    private ExtensionRuntimeEndpointContributions $endpointContributions;

    private ExtensionRuntimeSchedulerContributions $schedulerContributions;

    private array $extensionSettingDefinitions = [];

    private array $cookieConsentDefinitions = [];

    private array $databaseTables = [];

    private array $contentSchemaDefinitions = [];

    public function __clone(): void
    {
        $this->viewContributions = clone $this->viewContributions;
        $this->endpointContributions = clone $this->endpointContributions;
        $this->schedulerContributions = clone $this->schedulerContributions;
    }

    private function guard(): ExtensionRuntimeContributionGuard
    {
        return $this->contributionGuard ?? new ExtensionRuntimeContributionGuard();
    }

    public function add(Extension $extension, mixed $contribution): void
    {
        $staged = clone $this;
        foreach (($this->contributionExpander ?? new ExtensionRuntimeContributionExpander())->expand($extension, $contribution) as $expandedContribution) {
            $staged->addToRegistry($extension, $expandedContribution);
        }

        $this->replaceWith($staged);
    }

    private function addToRegistry(Extension $extension, object $contribution): void
    {
        if ($contribution instanceof StaticViewInjection) {
            $this->viewContributions->addStatic($contribution);

            return;
        }

        if ($contribution instanceof ConfigurableStaticViewInjectionSet) {
            $this->viewContributions->addConfigurableStaticSet($contribution);

            return;
        }

        if ($contribution instanceof DynamicViewInjection) {
            $this->viewContributions->addDynamic($contribution);

            return;
        }

        if ($contribution instanceof ExtensionSettingDefinition) {
            $this->guard()->assertSettingDefinition($extension, $contribution);
            $this->extensionSettingDefinitions[] = $contribution;

            return;
        }

        if ($contribution instanceof SchedulerTaskDefinition) {
            $this->addSchedulerTaskDefinition($extension, $contribution);

            return;
        }

        if ($contribution instanceof ApiEndpointDefinition) {
            $this->endpointContributions->addApiEndpoint($extension, $contribution, $this->guard());

            return;
        }

        if ($contribution instanceof ApiEndpointHandlerInterface) {
            $this->endpointContributions->addApiEndpointHandler($extension, $contribution, $this->guard());

            return;
        }

        if ($contribution instanceof LiveEndpointDefinition) {
            $this->endpointContributions->addLiveEndpoint($extension, $contribution, $this->guard());

            return;
        }

        if ($contribution instanceof LiveEndpointHandlerInterface) {
            $this->endpointContributions->addLiveEndpointHandler($extension, $contribution, $this->guard());

            return;
        }

        if ($contribution instanceof CookieConsentDefinition) {
            $this->addCookieConsentDefinition($extension, $contribution);

            return;
        }

        if ($contribution instanceof ExtensionDatabaseTable) {
            $this->addDatabaseTable($extension, $contribution);

            return;
        }

        if ($contribution instanceof ExtensionContentSchemaDefinition) {
            $this->addContentSchemaDefinition($extension, $contribution);

            return;
        }

        $schedulerProviderHandled = false;

        if ($contribution instanceof SchedulerCallableProviderInterface) {
            $this->schedulerContributions->addCallableProvider($extension, $contribution);
            $schedulerProviderHandled = true;
        }

        if ($contribution instanceof SchedulerActionQueueProviderInterface) {
            $this->schedulerContributions->addActionQueueProvider($extension, $contribution);
            $schedulerProviderHandled = true;
        }

        if ($schedulerProviderHandled) {
            return;
        }
    }

    private function replaceWith(self $registry): void
    {
        $this->viewContributions = clone $registry->viewContributions;
        $this->endpointContributions = clone $registry->endpointContributions;
        $this->schedulerContributions = clone $registry->schedulerContributions;
        $this->extensionSettingDefinitions = $registry->extensionSettingDefinitions;
        $this->cookieConsentDefinitions = $registry->cookieConsentDefinitions;
        $this->databaseTables = $registry->databaseTables;
        $this->contentSchemaDefinitions = $registry->contentSchemaDefinitions;
    }

    private function addSchedulerTaskDefinition(Extension $extension, SchedulerTaskDefinition $definition): void
    {
        $this->schedulerContributions->addTask($extension, $definition, $this->guard());
    }

    private function addCookieConsentDefinition(Extension $extension, CookieConsentDefinition $definition): void
    {
        $this->guard()->assertCookieConsentDefinition($extension, $definition, $this->existingCookieConsentNames());
        $this->cookieConsentDefinitions[] = $definition;
    }

    private function addDatabaseTable(Extension $extension, ExtensionDatabaseTable $table): void
    {
        $this->guard()->assertDatabaseTable($extension, $table);
        $this->databaseTables[] = $table;
    }

    private function addContentSchemaDefinition(Extension $extension, ExtensionContentSchemaDefinition $definition): void
    {
        $this->guard()->assertContentSchema($extension, $definition);
        $this->contentSchemaDefinitions[] = $definition;
    }

    /**
     * @return list<string>
     */
    private function existingCookieConsentNames(): array
    {
        return [
            ...array_map(
                static fn (CookieConsentDefinition $definition): string => $definition->name(),
                $this->cookieConsentDefinitions,
            ),
        ];
    }

    public function staticViewInjections(): array
    {
        return $this->viewContributions->staticViewInjections();
    }

    public function dynamicViewInjections(): array
    {
        return $this->viewContributions->dynamicViewInjections();
    }

    public function extensionSettings(): array
    {
        return $this->extensionSettingDefinitions;
    }

    public function apiEndpoints(): array
    {
        return $this->endpointContributions->apiEndpoints();
    }

    public function apiEndpointHandlers(): array
    {
        return $this->endpointContributions->apiEndpointHandlers();
    }

    public function liveEndpoints(): array
    {
        return $this->endpointContributions->liveEndpoints();
    }

    public function liveEndpointHandlers(): array
    {
        return $this->endpointContributions->liveEndpointHandlers();
    }

    public function cookieConsentDefinitions(): array
    {
        return $this->cookieConsentDefinitions;
    }

    public function schedulerTasks(): array
    {
        return $this->schedulerContributions->schedulerTasks();
    }

    /**
     * @return list<ExtensionDatabaseTable>
     */
    public function extensionDatabaseTables(): array
    {
        return $this->databaseTables;
    }

    /**
     * @return list<ExtensionContentSchemaDefinition>
     */
    public function extensionContentSchemas(): array
    {
        return $this->contentSchemaDefinitions;
    }

    public function schedulerCallable(string $target): ?callable
    {
        return $this->schedulerContributions->schedulerCallable($target);
    }

    public function schedulerActionQueue(string $target): ?ActionQueue
    {
        return $this->schedulerContributions->schedulerActionQueue($target);
    }
}
