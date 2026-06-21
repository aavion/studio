<?php

declare(strict_types=1);

namespace App\Core\Extension\Contribution;

use App\Core\Extension\Settings\ExtensionSettings;
use App\View\Injection\ConfigurableStaticViewInjectionSet;
use App\View\Injection\DynamicViewInjection;
use App\View\Injection\DynamicViewInjectionProviderInterface;
use App\View\Injection\StaticViewInjection;
use App\View\Injection\StaticViewInjectionProviderInterface;

final class ExtensionRuntimeViewContributions implements StaticViewInjectionProviderInterface, DynamicViewInjectionProviderInterface
{
    private array $staticViewInjections = [];

    private array $configurableStaticViewInjectionSets = [];

    private array $dynamicViewInjections = [];

    public function __construct(private ?ExtensionSettings $extensionSettingsStore = null)
    {
    }

    public function addStatic(StaticViewInjection $injection): void
    {
        $this->staticViewInjections[] = $injection;
    }

    public function addConfigurableStaticSet(ConfigurableStaticViewInjectionSet $set): void
    {
        $this->configurableStaticViewInjectionSets[] = $set;
    }

    public function addDynamic(DynamicViewInjection $injection): void
    {
        $this->dynamicViewInjections[] = $injection;
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
}
