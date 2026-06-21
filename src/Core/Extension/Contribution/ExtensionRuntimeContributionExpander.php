<?php

declare(strict_types=1);

namespace App\Core\Extension\Contribution;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointHandlerInterface;
use App\Api\Endpoint\ApiEndpointHandlerProviderInterface;
use App\Api\Endpoint\ApiEndpointProviderInterface;
use App\Core\Extension\Content\ExtensionContentSchemaDefinition;
use App\Core\Extension\Content\ExtensionContentSchemaProviderInterface;
use App\Core\Extension\Database\ExtensionDatabaseProviderInterface;
use App\Core\Extension\Database\ExtensionDatabaseTable;
use App\Core\Extension\ExtensionContributionContext;
use App\Core\Extension\ExtensionMessageKey;
use App\Core\Extension\ExtensionRuntimeContributionFactory;
use App\Core\Extension\Settings\ExtensionSettingDefinition;
use App\Core\Extension\Settings\ExtensionSettingProviderInterface;
use App\Core\Message\MessageException;
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

final readonly class ExtensionRuntimeContributionExpander
{
    /**
     * @return iterable<object>
     */
    public function expand(Extension $extension, mixed $contribution): iterable
    {
        if (null === $contribution) {
            return;
        }

        if ($this->isDirectContribution($contribution)) {
            yield $contribution;

            return;
        }

        if ($contribution instanceof ExtensionRuntimeContributionFactory) {
            yield from $this->expand($extension, $contribution->contributions(new ExtensionContributionContext($extension)));

            return;
        }

        $providerHandled = false;

        yield from $this->expandViewContributions($extension, $contribution, $providerHandled);
        yield from $this->expandApiContributions($extension, $contribution, $providerHandled);
        yield from $this->expandLiveContributions($extension, $contribution, $providerHandled);
        yield from $this->expandOperationalContributions($extension, $contribution, $providerHandled);

        if ($providerHandled) {
            return;
        }

        if (is_iterable($contribution)) {
            foreach ($contribution as $item) {
                yield from $this->expand($extension, $item);
            }

            return;
        }

        throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_RUNTIME_CONTRIBUTION_UNSUPPORTED, [
            '%extension%' => $extension->extensionName(),
            '%type%' => get_debug_type($contribution),
        ]);
    }

    private function isDirectContribution(mixed $contribution): bool
    {
        return $contribution instanceof StaticViewInjection
            || $contribution instanceof ConfigurableStaticViewInjectionSet
            || $contribution instanceof DynamicViewInjection
            || $contribution instanceof ExtensionSettingDefinition
            || $contribution instanceof SchedulerTaskDefinition
            || $contribution instanceof ApiEndpointDefinition
            || $contribution instanceof ApiEndpointHandlerInterface
            || $contribution instanceof LiveEndpointDefinition
            || $contribution instanceof LiveEndpointHandlerInterface
            || $contribution instanceof CookieConsentDefinition
            || $contribution instanceof ExtensionDatabaseTable
            || $contribution instanceof ExtensionContentSchemaDefinition;
    }

    /**
     * @return iterable<object>
     */
    private function expandViewContributions(Extension $extension, mixed $contribution, bool &$providerHandled): iterable
    {
        if ($contribution instanceof StaticViewInjectionProviderInterface) {
            foreach ($contribution->staticViewInjections() as $injection) {
                yield from $this->expand($extension, $injection);
            }

            $providerHandled = true;
        }

        if ($contribution instanceof DynamicViewInjectionProviderInterface) {
            foreach ($contribution->dynamicViewInjections() as $injection) {
                yield from $this->expand($extension, $injection);
            }

            $providerHandled = true;
        }

        if ($contribution instanceof ExtensionSettingProviderInterface) {
            foreach ($contribution->extensionSettings() as $definition) {
                yield from $this->expand($extension, $definition);
            }

            $providerHandled = true;
        }
    }

    /**
     * @return iterable<object>
     */
    private function expandApiContributions(Extension $extension, mixed $contribution, bool &$providerHandled): iterable
    {
        if ($contribution instanceof ApiEndpointProviderInterface) {
            foreach ($contribution->apiEndpoints() as $definition) {
                yield from $this->expand($extension, $definition);
            }

            $providerHandled = true;
        }

        if ($contribution instanceof ApiEndpointHandlerProviderInterface) {
            foreach ($contribution->apiEndpointHandlers() as $handler) {
                yield from $this->expand($extension, $handler);
            }

            $providerHandled = true;
        }
    }

    /**
     * @return iterable<object>
     */
    private function expandLiveContributions(Extension $extension, mixed $contribution, bool &$providerHandled): iterable
    {
        if ($contribution instanceof LiveEndpointProviderInterface) {
            foreach ($contribution->liveEndpoints() as $definition) {
                yield from $this->expand($extension, $definition);
            }

            $providerHandled = true;
        }

        if ($contribution instanceof LiveEndpointHandlerProviderInterface) {
            foreach ($contribution->liveEndpointHandlers() as $handler) {
                yield from $this->expand($extension, $handler);
            }

            $providerHandled = true;
        }
    }

    /**
     * @return iterable<object>
     */
    private function expandOperationalContributions(Extension $extension, mixed $contribution, bool &$providerHandled): iterable
    {
        if ($contribution instanceof CookieConsentProviderInterface) {
            foreach ($contribution->cookieConsentDefinitions() as $definition) {
                yield from $this->expand($extension, $definition);
            }

            $providerHandled = true;
        }

        if ($contribution instanceof SchedulerTaskProviderInterface) {
            foreach ($contribution->schedulerTasks() as $definition) {
                yield from $this->expand($extension, $definition);
            }

            $providerHandled = true;
        }

        if ($contribution instanceof SchedulerCallableProviderInterface || $contribution instanceof SchedulerActionQueueProviderInterface) {
            yield $contribution;
            $providerHandled = true;
        }

        if ($contribution instanceof ExtensionDatabaseProviderInterface) {
            foreach ($contribution->extensionDatabaseTables() as $table) {
                yield from $this->expand($extension, $table);
            }

            $providerHandled = true;
        }

        if ($contribution instanceof ExtensionContentSchemaProviderInterface) {
            foreach ($contribution->extensionContentSchemas() as $definition) {
                yield from $this->expand($extension, $definition);
            }

            $providerHandled = true;
        }
    }
}
