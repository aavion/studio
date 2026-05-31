<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\AclGroup;
use App\Entity\UserAccount;

final readonly class AccountReactivationAccessResolver
{
    public function role(UserAccount $user): UserRole
    {
        return UserRole::Public === $user->role() ? UserRole::User : $user->role();
    }

    /**
     * @return list<string>
     */
    public function groupIdentifiers(UserAccount $user, UserRole $role): array
    {
        $identifiers = [];

        foreach ($user->groups() as $group) {
            if ($group instanceof AclGroup && $role->accessLevel() >= $group->minRole()) {
                $identifiers[] = $group->identifier();
            }
        }

        sort($identifiers);

        return array_values(array_unique($identifiers));
    }
}
