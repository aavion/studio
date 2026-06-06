<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\AclGroup;

interface AclGroupReferenceProviderInterface
{
    public function key(): string;

    /**
     * @return list<array<string, mixed>>
     */
    public function impact(AclGroup $group): array;

    public function removeReferences(AclGroup $group): void;

    public function removeBelowMinRoleReferences(AclGroup $group, int $minRole): int;
}
