<?php

declare(strict_types=1);

namespace App\Core\AdminAcl;

use Psr\Cache\CacheItemInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Throwable;

final class AdminFeatureRegistry
{
    public const CACHE_KEY = 'admin_acl.feature_definitions.v1';

    private const CACHE_TTL_SECONDS = 300;

    /**
     * @var list<AdminFeatureDefinition>|null
     */
    private ?array $allDefinitions = null;

    /**
     * @var array<string, AdminFeatureDefinition>|null
     */
    private ?array $definitionsByIdentifier = null;

    /**
     * @param iterable<AdminFeatureProviderInterface> $providers
     */
    public function __construct(private readonly iterable $providers, private readonly ?CacheInterface $cache = null)
    {
    }

    /**
     * @return list<AdminFeatureDefinition>
     */
    public function definitions(?AdminPermissionSurface $surface = null): array
    {
        $definitions = array_filter(
            $this->allDefinitions(),
            static fn (AdminFeatureDefinition $definition): bool => null === $surface || $definition->surface() === $surface,
        );

        usort(
            $definitions,
            static fn (AdminFeatureDefinition $left, AdminFeatureDefinition $right): int => [
                $left->surface()->value,
                $left->sortOrder(),
                $left->identifier(),
            ] <=> [
                $right->surface()->value,
                $right->sortOrder(),
                $right->identifier(),
            ],
        );

        return array_values($definitions);
    }

    public function find(string $identifier): ?AdminFeatureDefinition
    {
        return $this->definitionMap()[$identifier] ?? null;
    }

    /**
     * @return list<AdminFeatureDefinition>
     */
    private function allDefinitions(): array
    {
        if (null !== $this->allDefinitions) {
            return $this->allDefinitions;
        }

        if (null !== $this->cache) {
            try {
                return $this->allDefinitions = $this->cache->get(
                    self::CACHE_KEY,
                    function (CacheItemInterface $item): array {
                        $item->expiresAfter(self::CACHE_TTL_SECONDS);

                        return $this->loadDefinitions();
                    },
                );
            } catch (Throwable) {
                return $this->allDefinitions = $this->loadDefinitions();
            }
        }

        return $this->allDefinitions = $this->loadDefinitions();
    }

    public function resetCache(): void
    {
        $this->allDefinitions = null;
        $this->definitionsByIdentifier = null;

        try {
            $this->cache?->delete(self::CACHE_KEY);
        } catch (Throwable) {
        }
    }

    /**
     * @return array<string, AdminFeatureDefinition>
     */
    private function definitionMap(): array
    {
        if (null !== $this->definitionsByIdentifier) {
            return $this->definitionsByIdentifier;
        }

        $map = [];
        foreach ($this->allDefinitions() as $definition) {
            $map[$definition->identifier()] = $definition;
        }

        return $this->definitionsByIdentifier = $map;
    }

    /**
     * @return list<AdminFeatureDefinition>
     */
    private function loadDefinitions(): array
    {
        $definitions = [];
        $seen = [];

        foreach ($this->providers as $provider) {
            foreach ($provider->adminFeatures() as $definition) {
                if (isset($seen[$definition->identifier()])) {
                    continue;
                }

                $seen[$definition->identifier()] = true;
                $definitions[] = $definition;
            }
        }

        return $definitions;
    }
}
