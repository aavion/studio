<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Access\AccessActor;
use App\Entity\AclGroup;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AdminUserAssignmentOptions
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AdminUserAccessPolicy $adminUserPolicy,
    ) {
    }

    /**
     * @return list<AclGroup>
     */
    public function groups(AccessActor $actor, ?UserRole $targetRole = null): array
    {
        return array_values(array_filter(
            $this->entityManager->getRepository(AclGroup::class)->findBy([], ['minRole' => 'ASC', 'identifier' => 'ASC']),
            fn (mixed $group): bool => $group instanceof AclGroup && $this->adminUserPolicy->canAssignGroup($actor, $group, $targetRole),
        ));
    }

    /**
     * @return list<UserRole>
     */
    public function roles(AccessActor $actor): array
    {
        return array_values(array_filter(
            UserRole::assignable(),
            fn (UserRole $role): bool => null === $this->adminUserPolicy->validateRoleAssignment($actor, $role),
        ));
    }

    /**
     * @return array<string, list<array{identifier: string, label: string}>>
     */
    public function groupOptionsByRole(AccessActor $actor): array
    {
        $groupsByRole = [];

        foreach ($this->roles($actor) as $role) {
            $groupsByRole[$role->value] = array_map(
                fn (AclGroup $group): array => [
                    'identifier' => $group->identifier(),
                    'label' => $group->name(),
                ],
                $this->groups($actor, $role),
            );
        }

        return $groupsByRole;
    }
}
