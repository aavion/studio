<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointHandlerInterface;
use App\Api\Endpoint\ApiEndpointHandlerProviderInterface;
use App\Api\Endpoint\ApiEndpointProviderInterface;
use App\Core\Message\MessageException;
use App\Core\Operation\ActionQueue;
use App\Core\Extension\Content\ExtensionContentSchemaDefinition;
use App\Core\Extension\Content\ExtensionContentSchemaProviderInterface;
use App\Core\Extension\Database\ExtensionDatabaseProviderInterface;
use App\Core\Extension\Database\ExtensionDatabaseTable;
use App\Core\Extension\Settings\ExtensionSettingDefinition;
use App\Core\Extension\Settings\ExtensionSettingProviderInterface;
use App\Core\Extension\Settings\ExtensionSettings;
use App\Core\Statistics\VisitorIdGenerator;
use App\Entity\Extension;
use App\Live\LiveEndpointDefinition;
use App\Live\LiveEndpointHandlerInterface;
use App\Live\LiveEndpointHandlerProviderInterface;
use App\Live\LiveEndpointProviderInterface;
use App\Privacy\Cookie\CookieConsentDefinition;
use App\Privacy\Cookie\CookieConsentManager;
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
use Symfony\Component\HttpFoundation\Cookie;

final class ExtensionRuntimeContributionRegistry implements StaticViewInjectionProviderInterface, DynamicViewInjectionProviderInterface, ExtensionSettingProviderInterface, ApiEndpointProviderInterface, ApiEndpointHandlerProviderInterface, LiveEndpointProviderInterface, LiveEndpointHandlerProviderInterface, CookieConsentProviderInterface, SchedulerTaskProviderInterface, SchedulerCallableProviderInterface, SchedulerActionQueueProviderInterface, ExtensionDatabaseProviderInterface, ExtensionContentSchemaProviderInterface
{
    private const RESERVED_COOKIE_NAMES = [
        CookieConsentManager::CONSENT_COOKIE_NAME,
        'PHPSESSID',
        VisitorIdGenerator::COOKIE_NAME,
    ];

    public function __construct(private ?ExtensionSettings $extensionSettingsStore = null)
    {
    }

    private array $staticViewInjections = [];

    private array $configurableStaticViewInjectionSets = [];

    private array $dynamicViewInjections = [];

    private array $extensionSettingDefinitions = [];

    private array $apiEndpointDefinitions = [];

    private array $apiEndpointHandlers = [];

    private array $liveEndpointDefinitions = [];

    private array $liveEndpointHandlers = [];

    private array $cookieConsentDefinitions = [];

    private array $schedulerTaskDefinitions = [];

    private array $schedulerCallableProviders = [];

    private array $schedulerActionQueueProviders = [];

    private array $databaseTables = [];

    private array $contentSchemaDefinitions = [];

    public function add(Extension $extension, mixed $contribution): void
    {
        $staged = clone $this;
        $staged->addToRegistry($extension, $contribution);
        $this->replaceWith($staged);
    }

    private function addToRegistry(Extension $extension, mixed $contribution): void
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

        if ($contribution instanceof ExtensionSettingDefinition) {
            $this->extensionSettingDefinitions[] = $contribution;

            return;
        }

        if ($contribution instanceof SchedulerTaskDefinition) {
            $this->addSchedulerTaskDefinition($extension, $contribution);

            return;
        }

        if ($contribution instanceof ApiEndpointDefinition) {
            $this->addApiEndpointDefinition($extension, $contribution);

            return;
        }

        if ($contribution instanceof ApiEndpointHandlerInterface) {
            $this->addApiEndpointHandler($extension, $contribution);

            return;
        }

        if ($contribution instanceof LiveEndpointDefinition) {
            $this->addLiveEndpointDefinition($extension, $contribution);

            return;
        }

        if ($contribution instanceof LiveEndpointHandlerInterface) {
            $this->addLiveEndpointHandler($extension, $contribution);

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

        $providerHandled = false;

        if ($contribution instanceof StaticViewInjectionProviderInterface) {
            foreach ($contribution->staticViewInjections() as $injection) {
                $this->addToRegistry($extension, $injection);
            }

            $providerHandled = true;
        }

        if ($contribution instanceof DynamicViewInjectionProviderInterface) {
            foreach ($contribution->dynamicViewInjections() as $injection) {
                $this->addToRegistry($extension, $injection);
            }

            $providerHandled = true;
        }

        if ($contribution instanceof ExtensionSettingProviderInterface) {
            foreach ($contribution->extensionSettings() as $definition) {
                $this->addToRegistry($extension, $definition);
            }

            $providerHandled = true;
        }

        if ($contribution instanceof ApiEndpointProviderInterface) {
            foreach ($contribution->apiEndpoints() as $definition) {
                $this->addToRegistry($extension, $definition);
            }

            $providerHandled = true;
        }

        if ($contribution instanceof ApiEndpointHandlerProviderInterface) {
            foreach ($contribution->apiEndpointHandlers() as $handler) {
                $this->addToRegistry($extension, $handler);
            }

            $providerHandled = true;
        }

        if ($contribution instanceof LiveEndpointProviderInterface) {
            foreach ($contribution->liveEndpoints() as $definition) {
                $this->addToRegistry($extension, $definition);
            }

            $providerHandled = true;
        }

        if ($contribution instanceof LiveEndpointHandlerProviderInterface) {
            foreach ($contribution->liveEndpointHandlers() as $handler) {
                $this->addToRegistry($extension, $handler);
            }

            $providerHandled = true;
        }

        if ($contribution instanceof CookieConsentProviderInterface) {
            foreach ($contribution->cookieConsentDefinitions() as $definition) {
                $this->addToRegistry($extension, $definition);
            }

            $providerHandled = true;
        }

        if ($contribution instanceof SchedulerTaskProviderInterface) {
            foreach ($contribution->schedulerTasks() as $definition) {
                $this->addToRegistry($extension, $definition);
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

        if ($contribution instanceof ExtensionDatabaseProviderInterface) {
            foreach ($contribution->extensionDatabaseTables() as $table) {
                $this->addToRegistry($extension, $table);
            }

            $providerHandled = true;
        }

        if ($contribution instanceof ExtensionContentSchemaProviderInterface) {
            foreach ($contribution->extensionContentSchemas() as $definition) {
                $this->addToRegistry($extension, $definition);
            }

            $providerHandled = true;
        }

        if ($providerHandled) {
            return;
        }

        if (is_iterable($contribution)) {
            foreach ($contribution as $item) {
                $this->addToRegistry($extension, $item);
            }

            return;
        }

        throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_RUNTIME_CONTRIBUTION_UNSUPPORTED, [
            '%extension%' => $extension->extensionName(),
            '%type%' => get_debug_type($contribution),
        ]);
    }

    private function replaceWith(self $registry): void
    {
        $this->staticViewInjections = $registry->staticViewInjections;
        $this->configurableStaticViewInjectionSets = $registry->configurableStaticViewInjectionSets;
        $this->dynamicViewInjections = $registry->dynamicViewInjections;
        $this->extensionSettingDefinitions = $registry->extensionSettingDefinitions;
        $this->apiEndpointDefinitions = $registry->apiEndpointDefinitions;
        $this->apiEndpointHandlers = $registry->apiEndpointHandlers;
        $this->liveEndpointDefinitions = $registry->liveEndpointDefinitions;
        $this->liveEndpointHandlers = $registry->liveEndpointHandlers;
        $this->cookieConsentDefinitions = $registry->cookieConsentDefinitions;
        $this->schedulerTaskDefinitions = $registry->schedulerTaskDefinitions;
        $this->schedulerCallableProviders = $registry->schedulerCallableProviders;
        $this->schedulerActionQueueProviders = $registry->schedulerActionQueueProviders;
        $this->databaseTables = $registry->databaseTables;
        $this->contentSchemaDefinitions = $registry->contentSchemaDefinitions;
    }

    private function addSchedulerTaskDefinition(Extension $extension, SchedulerTaskDefinition $definition): void
    {
        if ($definition->source() !== $extension->extensionName()) {
            throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_SCHEDULER_SOURCE_INVALID, [
                '%task%' => $definition->identifier(),
                '%extension%' => $extension->extensionName(),
                '%source%' => $definition->source(),
            ]);
        }

        if ($definition->trusted()) {
            throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_SCHEDULER_TRUSTED_BLOCKED, [
                '%task%' => $definition->identifier(),
                '%extension%' => $extension->extensionName(),
            ]);
        }

        $this->schedulerTaskDefinitions[] = $definition;
    }

    private function addApiEndpointDefinition(Extension $extension, ApiEndpointDefinition $definition): void
    {
        ExtensionApiContributionGuard::assertEndpoint($extension, $definition);
        $this->apiEndpointDefinitions[] = $definition;
    }

    private function addApiEndpointHandler(Extension $extension, ApiEndpointHandlerInterface $handler): void
    {
        ExtensionApiContributionGuard::assertHandler($extension, $handler);
        $this->apiEndpointHandlers[] = $handler;
    }

    private function addLiveEndpointDefinition(Extension $extension, LiveEndpointDefinition $definition): void
    {
        ExtensionLiveContributionGuard::assertEndpoint($extension, $definition);
        $this->liveEndpointDefinitions[] = $definition;
    }

    private function addLiveEndpointHandler(Extension $extension, LiveEndpointHandlerInterface $handler): void
    {
        ExtensionLiveContributionGuard::assertHandler($extension, $handler);
        $this->liveEndpointHandlers[] = $handler;
    }

    private function addCookieConsentDefinition(Extension $extension, CookieConsentDefinition $definition): void
    {
        if (in_array($definition->name(), $this->existingCookieConsentNames(), true)) {
            throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_RUNTIME_CONTRIBUTION_UNSUPPORTED, [
                '%extension%' => $extension->extensionName(),
                '%type%' => CookieConsentDefinition::class.'('.$definition->name().') duplicate',
            ]);
        }

        if ($definition->isNecessary() && !$this->necessaryExtensionCookieAllowed($extension, $definition->cookie())) {
            throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_RUNTIME_CONTRIBUTION_UNSUPPORTED, [
                '%extension%' => $extension->extensionName(),
                '%type%' => CookieConsentDefinition::class.'::necessary('.$definition->name().')',
            ]);
        }

        $this->cookieConsentDefinitions[] = $definition;
    }

    private function addDatabaseTable(Extension $extension, ExtensionDatabaseTable $table): void
    {
        if (!$extension->hasScope(ExtensionScope::Database)) {
            throw MessageException::forMessage(ExtensionMessageCode::EXTENSION_DATABASE_CONTRIBUTION_INVALID, ExtensionMessageKey::EXTENSION_DATABASE_CONTRIBUTION_INVALID, [
                '%reason%' => 'scope_missing',
            ], ['extension' => $extension->extensionName(), 'required_scope' => ExtensionScope::Database->value]);
        }

        $this->databaseTables[] = $table;
    }

    private function addContentSchemaDefinition(Extension $extension, ExtensionContentSchemaDefinition $definition): void
    {
        if (!$extension->hasScope(ExtensionScope::ContentSchema)) {
            throw MessageException::forMessage(ExtensionMessageCode::EXTENSION_CONTENT_SCHEMA_CONTRIBUTION_INVALID, ExtensionMessageKey::EXTENSION_CONTENT_SCHEMA_CONTRIBUTION_INVALID, [
                '%reason%' => 'scope_missing',
            ], ['extension' => $extension->extensionName(), 'required_scope' => ExtensionScope::ContentSchema->value]);
        }

        $this->contentSchemaDefinitions[] = $definition;
    }

    /**
     * @return list<string>
     */
    private function existingCookieConsentNames(): array
    {
        return [
            ...self::RESERVED_COOKIE_NAMES,
            ...array_map(
                static fn (CookieConsentDefinition $definition): string => $definition->name(),
                $this->cookieConsentDefinitions,
            ),
        ];
    }

    private function necessaryExtensionCookieAllowed(Extension $extension, Cookie $cookie): bool
    {
        $prefixes = $this->cookieNamePrefixes($extension);
        $sameSite = $cookie->getSameSite();

        return $this->cookieNameHasExtensionPrefix($cookie->getName(), $prefixes)
            && (null === $cookie->getDomain() || '' === trim($cookie->getDomain()))
            && in_array($sameSite, [Cookie::SAMESITE_LAX, Cookie::SAMESITE_STRICT], true);
    }

    /**
     * @return list<string>
     */
    private function cookieNamePrefixes(Extension $extension): array
    {
        $slug = strtolower($extension->extensionName());

        return array_values(array_unique([
            $slug.'_',
            str_replace('-', '_', $slug).'_',
        ]));
    }

    /**
     * @param list<string> $prefixes
     */
    private function cookieNameHasExtensionPrefix(string $name, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function staticViewInjections(): array
    {
        $injections = $this->staticViewInjections;

        foreach ($this->configurableStaticViewInjectionSets as $set) {
            $configuredBaseSlug = $this->extensionSettingsStore?->get(
                $set->extensionName(),
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

    public function extensionSettings(): array
    {
        return $this->extensionSettingDefinitions;
    }

    public function apiEndpoints(): array
    {
        return $this->apiEndpointDefinitions;
    }

    public function apiEndpointHandlers(): array
    {
        return $this->apiEndpointHandlers;
    }

    public function liveEndpoints(): array
    {
        return $this->liveEndpointDefinitions;
    }

    public function liveEndpointHandlers(): array
    {
        return $this->liveEndpointHandlers;
    }

    public function cookieConsentDefinitions(): array
    {
        return $this->cookieConsentDefinitions;
    }

    public function schedulerTasks(): array
    {
        return $this->schedulerTaskDefinitions;
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
