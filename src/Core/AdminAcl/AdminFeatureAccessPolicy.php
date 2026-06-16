<?php

declare(strict_types=1);

namespace App\Core\AdminAcl;

use App\Core\Access\AccessActor;
use App\Core\Access\AccessLevel;
use App\Entity\AclGroup;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Throwable;

final class AdminFeatureAccessPolicy
{
    /**
     * @var array<string, list<string>>
     */
    private array $allowedGroupsBySurface = [];

    public function __construct(
        private readonly AdminFeatureRegistry $registry,
        private readonly AdminFeatureOverrideStore $overrides,
        private readonly EntityManagerInterface $entityManager,
        private readonly ?CacheInterface $cache = null,
    ) {
    }

    public function state(string $feature, AccessActor $actor): AdminPermissionState
    {
        $definition = $this->registry->find($feature);

        if (!$definition instanceof AdminFeatureDefinition) {
            return AdminPermissionState::Denied;
        }

        if (!$this->parentFeaturesVisible($feature, $actor)) {
            return AdminPermissionState::Denied;
        }

        if ($actor->accessLevel() < $definition->surface()->gateAccessLevel()) {
            return AdminPermissionState::Denied;
        }

        if ($actor->accessLevel() >= AccessLevel::OWNER) {
            return $definition->ownerState();
        }

        $override = $definition->configurable() ? ($this->overrides->overrides()[$feature] ?? []) : [];
        return $this->stateWithGroupOverrides($definition, $actor, $override);
    }

    private function parentFeaturesVisible(string $feature, AccessActor $actor): bool
    {
        $parts = explode('.', $feature);

        while (count($parts) > 2) {
            array_pop($parts);
            $parent = implode('.', $parts);
            $definition = $this->registry->find($parent);

            if ($definition instanceof AdminFeatureDefinition && !$this->stateWithoutParentGate($parent, $actor)->isVisible()) {
                return false;
            }
        }

        return true;
    }

    private function stateWithoutParentGate(string $feature, AccessActor $actor): AdminPermissionState
    {
        $definition = $this->registry->find($feature);

        if (!$definition instanceof AdminFeatureDefinition) {
            return AdminPermissionState::Denied;
        }

        if ($actor->accessLevel() < $definition->surface()->gateAccessLevel()) {
            return AdminPermissionState::Denied;
        }

        if ($actor->accessLevel() >= AccessLevel::OWNER) {
            return $definition->ownerState();
        }

        $override = $definition->configurable() ? ($this->overrides->overrides()[$feature] ?? []) : [];
        return $this->stateWithGroupOverrides($definition, $actor, $override);
    }

    /**
     * @param array<string, mixed> $override
     */
    private function stateWithGroupOverrides(AdminFeatureDefinition $definition, AccessActor $actor, array $override): AdminPermissionState
    {
        $roleState = AdminPermissionState::fromMixed($override['state'] ?? null, $definition->defaultState());
        $groups = is_array($override['groups'] ?? null) ? $override['groups'] : [];
        $allowedGroups = $this->allowedGroupIdentifiers($definition->surface());
        $effectiveGroupState = null;

        foreach ($groups as $identifier => $submittedState) {
            if (
                is_string($identifier)
                && in_array($identifier, $allowedGroups, true)
                && $actor->hasGroupIdentifier($identifier)
            ) {
                $candidate = AdminPermissionState::fromMixed($submittedState);
                $effectiveGroupState = null === $effectiveGroupState ? $candidate : AdminPermissionState::max($effectiveGroupState, $candidate);
            }
        }

        return $effectiveGroupState ?? $roleState;
    }

    public function isVisible(string $feature, AccessActor $actor): bool
    {
        return $this->state($feature, $actor)->isVisible();
    }

    public function isMutable(string $feature, AccessActor $actor): bool
    {
        return $this->state($feature, $actor)->isMutable();
    }

    /**
     * @return list<array{identifier: string, name: string, min_role: int}>
     */
    public function availableGroups(AdminPermissionSurface $surface): array
    {
        $key = $this->groupsCacheKey($surface);

        if (null !== $this->cache) {
            try {
                return $this->cache->get(
                    $key,
                    function (CacheItemInterface $item) use ($surface): array {
                        $item->expiresAfter(300);

                        return $this->loadAvailableGroups($surface);
                    },
                );
            } catch (Throwable) {
                return $this->loadAvailableGroups($surface);
            }
        }

        return $this->loadAvailableGroups($surface);
    }

    public function resetCache(): void
    {
        $this->allowedGroupsBySurface = [];

        foreach (AdminPermissionSurface::cases() as $surface) {
            try {
                $this->cache?->delete($this->groupsCacheKey($surface));
            } catch (Throwable) {
            }
        }
    }

    /**
     * @return list<array{identifier: string, name: string, min_role: int}>
     */
    private function loadAvailableGroups(AdminPermissionSurface $surface): array
    {
        $groups = $this->entityManager->createQueryBuilder()
            ->select('aclGroup')
            ->from(AclGroup::class, 'aclGroup')
            ->andWhere('aclGroup.minRole >= :minRole')
            ->setParameter('minRole', $surface->gateAccessLevel())
            ->orderBy('aclGroup.identifier', 'ASC')
            ->getQuery()
            ->getResult();

        return array_values(array_map(
            static fn (AclGroup $group): array => [
                'identifier' => $group->identifier(),
                'name' => $group->name(),
                'min_role' => $group->minRole(),
            ],
            $groups,
        ));
    }

    private function groupsCacheKey(AdminPermissionSurface $surface): string
    {
        return 'admin_acl.available_groups.'.$surface->value.'.v1';
    }

    /**
     * @return list<string>
     */
    private function allowedGroupIdentifiers(AdminPermissionSurface $surface): array
    {
        $key = $surface->value;

        if (!isset($this->allowedGroupsBySurface[$key])) {
            $this->allowedGroupsBySurface[$key] = array_map(
                static fn (array $group): string => $group['identifier'],
                $this->availableGroups($surface),
            );
        }

        return $this->allowedGroupsBySurface[$key];
    }
}
