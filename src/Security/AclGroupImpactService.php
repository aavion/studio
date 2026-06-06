<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\AclGroup;

final readonly class AclGroupImpactService
{
    /**
     * @param iterable<AclGroupReferenceProviderInterface> $providers
     */
    public function __construct(
        private iterable $providers,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function impact(AclGroup $group): array
    {
        $impact = [
            'group_identifier' => $group->identifier(),
            'summary' => [],
        ];

        foreach ($this->providers() as $provider) {
            $key = $provider->key();
            $rows = $provider->impact($group);

            $impact[$key] = $rows;
            $impact['summary'][$key] = count($rows);
        }

        return $impact;
    }

    /**
     * @return array<string, mixed>
     */
    public function removeReferences(AclGroup $group): array
    {
        $impact = $this->impact($group);

        foreach ($this->providers() as $provider) {
            $provider->removeReferences($group);
        }

        return $impact;
    }

    /**
     * @return array<string, int>
     */
    public function removeBelowMinRoleReferences(AclGroup $group, int $minRole): array
    {
        $summary = [];

        foreach ($this->providers() as $provider) {
            $removed = $provider->removeBelowMinRoleReferences($group, $minRole);

            if (0 < $removed) {
                $summary[$provider->key()] = $removed;
            }
        }

        return $summary + [
            'users' => 0,
            'account_tokens' => 0,
        ];
    }

    /**
     * @return list<AclGroupReferenceProviderInterface>
     */
    private function providers(): array
    {
        return array_values(array_filter(
            $this->providers instanceof \Traversable ? iterator_to_array($this->providers) : (array) $this->providers,
            static fn (mixed $provider): bool => $provider instanceof AclGroupReferenceProviderInterface,
        ));
    }
}
