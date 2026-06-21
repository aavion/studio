<?php

declare(strict_types=1);

namespace App\Core\Config\Settings;

use App\Core\AdminAcl\AdminFeatureDefaults;
use App\Core\AdminAcl\AdminFeatureOverrideStore;
use App\Core\Config\ConfigDefaultProviderInterface;
use App\Core\Extension\ExtensionHttpRequest;

final class CoreConfigDefaultProvider implements ConfigDefaultProviderInterface
{
    /**
     * @var array<string, mixed>|null
     */
    private ?array $defaults = null;

    public function __construct(
        private readonly CoreSettingsRegistry $registry,
        private readonly ?AdminFeatureDefaults $adminFeatureDefaults = null,
    ) {
    }

    public function hasDefault(string $key): bool
    {
        return array_key_exists($key, $this->defaults());
    }

    public function defaultValue(string $key): mixed
    {
        return $this->defaults()[$key] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        if (null !== $this->defaults) {
            return $this->defaults;
        }

        $defaults = [];

        foreach ($this->registry->allDefinitions() as $definition) {
            if (false === ($definition->metadata()['persist'] ?? true)) {
                continue;
            }

            $defaults[$definition->key()] = $definition->defaultValue();
        }

        $defaults[AdminFeatureOverrideStore::CONFIG_KEY] = ($this->adminFeatureDefaults ?? new AdminFeatureDefaults())->overrides();
        $defaults[ExtensionHttpRequest::ALLOW_PRIVATE_NETWORKS_KEY] = false;

        return $this->defaults = $defaults;
    }
}
