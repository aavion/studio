<?php

declare(strict_types=1);

namespace App\Core\AdminAcl;

use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use Psr\Cache\CacheItemInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Throwable;

final class AdminFeatureOverrideStore
{
    public const CONFIG_KEY = 'acl.admin.features';
    public const CACHE_KEY = 'admin_acl.feature_overrides.v1';

    private const CACHE_TTL_SECONDS = 300;

    /**
     * @var array<string, array{state?: string, groups?: array<string, string>}>|null
     */
    private ?array $overrides = null;

    public function __construct(
        private readonly Config $config,
        private readonly ?AdminFeatureDefaults $defaults = null,
        private readonly ?CacheInterface $cache = null,
    )
    {
    }

    /**
     * @return array<string, array{state?: string, groups?: array<string, string>}>
     */
    public function overrides(): array
    {
        if (null !== $this->overrides) {
            return $this->overrides;
        }

        if (null !== $this->cache) {
            try {
                return $this->overrides = $this->cache->get(
                    self::CACHE_KEY,
                    function (CacheItemInterface $item): array {
                        $item->expiresAfter(self::CACHE_TTL_SECONDS);

                        return $this->loadOverrides();
                    },
                );
            } catch (Throwable) {
                return $this->overrides = $this->loadOverrides();
            }
        }

        return $this->overrides = $this->loadOverrides();
    }

    /**
     * @return array<string, array{state?: string, groups?: array<string, string>}>
     */
    private function loadOverrides(): array
    {
        $value = $this->config->get(self::CONFIG_KEY, $this->defaultOverrides());

        $overrides = $this->defaultOverrides();

        if (!is_array($value)) {
            return $overrides;
        }

        foreach ($value as $feature => $override) {
            if (!is_string($feature) || !is_array($override)) {
                continue;
            }

            $groups = $override['groups'] ?? [];
            $overrides[$feature] = [
                'state' => is_string($override['state'] ?? null) ? $override['state'] : AdminPermissionState::Denied->value,
                'groups' => is_array($groups) ? $this->normalizeGroups($groups) : [],
            ];
        }

        return $overrides;
    }

    /**
     * @return array<string, array{state: string, groups: array<string, string>}>
     */
    public function defaultOverrides(): array
    {
        return $this->defaults?->overrides() ?? [];
    }

    /**
     * @param array<string, array{state: string, groups: array<string, string>}> $overrides
     */
    public function save(array $overrides, ?string $modifiedBy = null): bool
    {
        $saved = $this->config->set(self::CONFIG_KEY, $overrides, ConfigValueType::Json, modifiedBy: $modifiedBy);

        if ($saved) {
            $this->resetCache();
        }

        return $saved;
    }

    public function resetCache(): void
    {
        $this->overrides = null;

        try {
            $this->cache?->delete(self::CACHE_KEY);
        } catch (Throwable) {
        }
    }

    /**
     * @param array<mixed> $groups
     *
     * @return array<string, string>
     */
    private function normalizeGroups(array $groups): array
    {
        $normalized = [];

        foreach ($groups as $identifier => $state) {
            if (is_string($identifier) && is_string($state) && null !== AdminPermissionState::tryFrom($state)) {
                $normalized[$identifier] = $state;
            }
        }

        ksort($normalized);

        return $normalized;
    }
}
