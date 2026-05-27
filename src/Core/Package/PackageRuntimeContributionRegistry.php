<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Package\Settings\PackageSettingDefinition;
use App\Core\Package\Settings\PackageSettingProviderInterface;
use App\Core\Package\Settings\PackageSettings;
use App\Entity\ExtensionPackage;
use App\View\Injection\ConfigurableStaticViewInjectionSet;
use App\View\Injection\DynamicViewInjection;
use App\View\Injection\DynamicViewInjectionProviderInterface;
use App\View\Injection\StaticViewInjection;
use App\View\Injection\StaticViewInjectionProviderInterface;
use InvalidArgumentException;

final class PackageRuntimeContributionRegistry implements StaticViewInjectionProviderInterface, DynamicViewInjectionProviderInterface, PackageSettingProviderInterface
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
     * @var list<StaticViewInjectionProviderInterface>
     */
    private array $staticViewProviders = [];

    /**
     * @var list<DynamicViewInjectionProviderInterface>
     */
    private array $dynamicViewProviders = [];

    /**
     * @var list<PackageSettingProviderInterface>
     */
    private array $packageSettingProviders = [];

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

        if ($contribution instanceof StaticViewInjectionProviderInterface) {
            $this->staticViewProviders[] = $contribution;
        }

        if ($contribution instanceof DynamicViewInjectionProviderInterface) {
            $this->dynamicViewProviders[] = $contribution;
        }

        if ($contribution instanceof PackageSettingProviderInterface) {
            $this->packageSettingProviders[] = $contribution;
        }

        if ($contribution instanceof StaticViewInjectionProviderInterface
            || $contribution instanceof DynamicViewInjectionProviderInterface
            || $contribution instanceof PackageSettingProviderInterface
        ) {
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
        $this->staticViewProviders = $registry->staticViewProviders;
        $this->dynamicViewProviders = $registry->dynamicViewProviders;
        $this->packageSettingProviders = $registry->packageSettingProviders;
    }

    public function staticViewInjections(): array
    {
        $injections = $this->staticViewInjections;

        foreach ($this->staticViewProviders as $provider) {
            array_push($injections, ...$provider->staticViewInjections());
        }

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
        $injections = $this->dynamicViewInjections;

        foreach ($this->dynamicViewProviders as $provider) {
            array_push($injections, ...$provider->dynamicViewInjections());
        }

        return $injections;
    }

    public function packageSettings(): array
    {
        $settings = $this->packageSettingDefinitions;

        foreach ($this->packageSettingProviders as $provider) {
            array_push($settings, ...$provider->packageSettings());
        }

        return $settings;
    }
}
