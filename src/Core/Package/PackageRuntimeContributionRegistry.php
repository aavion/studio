<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointHandlerInterface;
use App\Api\Endpoint\ApiEndpointHandlerProviderInterface;
use App\Api\Endpoint\ApiEndpointProviderInterface;
use App\Core\Message\MessageException;
use App\Core\Operation\ActionQueue;
use App\Core\Package\Settings\PackageSettingDefinition;
use App\Core\Package\Settings\PackageSettingProviderInterface;
use App\Core\Package\Settings\PackageSettings;
use App\Core\Statistics\VisitorIdGenerator;
use App\Entity\ExtensionPackage;
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

final class PackageRuntimeContributionRegistry implements StaticViewInjectionProviderInterface, DynamicViewInjectionProviderInterface, PackageSettingProviderInterface, ApiEndpointProviderInterface, ApiEndpointHandlerProviderInterface, LiveEndpointProviderInterface, LiveEndpointHandlerProviderInterface, CookieConsentProviderInterface, SchedulerTaskProviderInterface, SchedulerCallableProviderInterface, SchedulerActionQueueProviderInterface
{
    private const RESERVED_COOKIE_NAMES = [
        CookieConsentManager::CONSENT_COOKIE_NAME,
        'PHPSESSID',
        VisitorIdGenerator::COOKIE_NAME,
    ];

    public function __construct(private ?PackageSettings $packageSettingsStore = null)
    {
    }

    private array $staticViewInjections = [];

    private array $configurableStaticViewInjectionSets = [];

    private array $dynamicViewInjections = [];

    private array $packageSettingDefinitions = [];

    private array $apiEndpointDefinitions = [];

    private array $apiEndpointHandlers = [];

    private array $liveEndpointDefinitions = [];

    private array $liveEndpointHandlers = [];

    private array $cookieConsentDefinitions = [];

    private array $schedulerTaskDefinitions = [];

    private array $schedulerCallableProviders = [];

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

        if ($contribution instanceof ApiEndpointDefinition) {
            $this->addApiEndpointDefinition($package, $contribution);

            return;
        }

        if ($contribution instanceof ApiEndpointHandlerInterface) {
            $this->addApiEndpointHandler($package, $contribution);

            return;
        }

        if ($contribution instanceof LiveEndpointDefinition) {
            $this->addLiveEndpointDefinition($package, $contribution);

            return;
        }

        if ($contribution instanceof LiveEndpointHandlerInterface) {
            $this->addLiveEndpointHandler($package, $contribution);

            return;
        }

        if ($contribution instanceof CookieConsentDefinition) {
            $this->addCookieConsentDefinition($package, $contribution);

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

        if ($contribution instanceof ApiEndpointProviderInterface) {
            foreach ($contribution->apiEndpoints() as $definition) {
                $this->addToRegistry($package, $definition);
            }

            $providerHandled = true;
        }

        if ($contribution instanceof ApiEndpointHandlerProviderInterface) {
            foreach ($contribution->apiEndpointHandlers() as $handler) {
                $this->addToRegistry($package, $handler);
            }

            $providerHandled = true;
        }

        if ($contribution instanceof LiveEndpointProviderInterface) {
            foreach ($contribution->liveEndpoints() as $definition) {
                $this->addToRegistry($package, $definition);
            }

            $providerHandled = true;
        }

        if ($contribution instanceof LiveEndpointHandlerProviderInterface) {
            foreach ($contribution->liveEndpointHandlers() as $handler) {
                $this->addToRegistry($package, $handler);
            }

            $providerHandled = true;
        }

        if ($contribution instanceof CookieConsentProviderInterface) {
            foreach ($contribution->cookieConsentDefinitions() as $definition) {
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

        throw MessageException::invalidArgument(PackageMessageKey::PACKAGE_RUNTIME_CONTRIBUTION_UNSUPPORTED, [
            '%package%' => $package->packageName(),
            '%type%' => get_debug_type($contribution),
        ]);
    }

    private function replaceWith(self $registry): void
    {
        $this->staticViewInjections = $registry->staticViewInjections;
        $this->configurableStaticViewInjectionSets = $registry->configurableStaticViewInjectionSets;
        $this->dynamicViewInjections = $registry->dynamicViewInjections;
        $this->packageSettingDefinitions = $registry->packageSettingDefinitions;
        $this->apiEndpointDefinitions = $registry->apiEndpointDefinitions;
        $this->apiEndpointHandlers = $registry->apiEndpointHandlers;
        $this->liveEndpointDefinitions = $registry->liveEndpointDefinitions;
        $this->liveEndpointHandlers = $registry->liveEndpointHandlers;
        $this->cookieConsentDefinitions = $registry->cookieConsentDefinitions;
        $this->schedulerTaskDefinitions = $registry->schedulerTaskDefinitions;
        $this->schedulerCallableProviders = $registry->schedulerCallableProviders;
        $this->schedulerActionQueueProviders = $registry->schedulerActionQueueProviders;
    }

    private function addSchedulerTaskDefinition(ExtensionPackage $package, SchedulerTaskDefinition $definition): void
    {
        if ($definition->source() !== $package->packageName()) {
            throw MessageException::invalidArgument(PackageMessageKey::PACKAGE_SCHEDULER_SOURCE_INVALID, [
                '%task%' => $definition->identifier(),
                '%package%' => $package->packageName(),
                '%source%' => $definition->source(),
            ]);
        }

        if ($definition->trusted()) {
            throw MessageException::invalidArgument(PackageMessageKey::PACKAGE_SCHEDULER_TRUSTED_BLOCKED, [
                '%task%' => $definition->identifier(),
                '%package%' => $package->packageName(),
            ]);
        }

        $this->schedulerTaskDefinitions[] = $definition;
    }

    private function addApiEndpointDefinition(ExtensionPackage $package, ApiEndpointDefinition $definition): void
    {
        PackageApiContributionGuard::assertEndpoint($package, $definition);
        $this->apiEndpointDefinitions[] = $definition;
    }

    private function addApiEndpointHandler(ExtensionPackage $package, ApiEndpointHandlerInterface $handler): void
    {
        PackageApiContributionGuard::assertHandler($package, $handler);
        $this->apiEndpointHandlers[] = $handler;
    }

    private function addLiveEndpointDefinition(ExtensionPackage $package, LiveEndpointDefinition $definition): void
    {
        PackageLiveContributionGuard::assertEndpoint($package, $definition);
        $this->liveEndpointDefinitions[] = $definition;
    }

    private function addLiveEndpointHandler(ExtensionPackage $package, LiveEndpointHandlerInterface $handler): void
    {
        PackageLiveContributionGuard::assertHandler($package, $handler);
        $this->liveEndpointHandlers[] = $handler;
    }

    private function addCookieConsentDefinition(ExtensionPackage $package, CookieConsentDefinition $definition): void
    {
        if (in_array($definition->name(), $this->existingCookieConsentNames(), true)) {
            throw MessageException::invalidArgument(PackageMessageKey::PACKAGE_RUNTIME_CONTRIBUTION_UNSUPPORTED, [
                '%package%' => $package->packageName(),
                '%type%' => CookieConsentDefinition::class.'('.$definition->name().') duplicate',
            ]);
        }

        if ($definition->isNecessary() && !$this->necessaryPackageCookieAllowed($package, $definition->cookie())) {
            throw MessageException::invalidArgument(PackageMessageKey::PACKAGE_RUNTIME_CONTRIBUTION_UNSUPPORTED, [
                '%package%' => $package->packageName(),
                '%type%' => CookieConsentDefinition::class.'::necessary('.$definition->name().')',
            ]);
        }

        $this->cookieConsentDefinitions[] = $definition;
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

    private function necessaryPackageCookieAllowed(ExtensionPackage $package, Cookie $cookie): bool
    {
        $prefixes = $this->cookieNamePrefixes($package);
        $sameSite = $cookie->getSameSite();

        return $this->cookieNameHasPackagePrefix($cookie->getName(), $prefixes)
            && (null === $cookie->getDomain() || '' === trim($cookie->getDomain()))
            && in_array($sameSite, [Cookie::SAMESITE_LAX, Cookie::SAMESITE_STRICT], true);
    }

    /**
     * @return list<string>
     */
    private function cookieNamePrefixes(ExtensionPackage $package): array
    {
        $slug = strtolower($package->packageName());

        return array_values(array_unique([
            $slug.'_',
            str_replace('-', '_', $slug).'_',
        ]));
    }

    /**
     * @param list<string> $prefixes
     */
    private function cookieNameHasPackagePrefix(string $name, array $prefixes): bool
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
