<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\AclGroup;
use App\Entity\UserAccount;
use Doctrine\ORM\EntityManagerInterface;

final readonly class UserGroupMembershipManager
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param list<string> $groupIdentifiers
     */
    public function addExisting(UserAccount $user, array $groupIdentifiers): void
    {
        foreach ($this->groups($groupIdentifiers) as $group) {
            $user->addGroup($group);
        }
    }

    /**
     * @param list<string> $groupIdentifiers
     */
    public function replaceExisting(UserAccount $user, array $groupIdentifiers): void
    {
        $groups = $this->groups($groupIdentifiers);
        $wanted = [];

        foreach ($groups as $group) {
            $wanted[$group->uid()] = $group;
        }

        foreach ($user->groups()->toArray() as $group) {
            if ($group instanceof AclGroup && !isset($wanted[$group->uid()])) {
                $user->removeGroup($group);
            }
        }

        foreach ($wanted as $group) {
            $user->addGroup($group);
        }
    }

    /**
     * @param list<string> $groupIdentifiers
     *
     * @return list<AclGroup>
     */
    public function groups(array $groupIdentifiers): array
    {
        if ([] === $groupIdentifiers) {
            return [];
        }

        $groups = [];

        foreach ($this->entityManager->getRepository(AclGroup::class)->findBy(['identifier' => $groupIdentifiers]) as $group) {
            if ($group instanceof AclGroup) {
                $groups[$group->identifier()] = $group;
            }
        }

        $ordered = [];
        foreach ($groupIdentifiers as $identifier) {
            if (isset($groups[$identifier])) {
                $ordered[] = $groups[$identifier];
            }
        }

        return $ordered;
    }

    /**
     * @return list<string>
     */
    public function identifiers(UserAccount $user): array
    {
        $identifiers = [];

        foreach ($user->groups() as $group) {
            if ($group instanceof AclGroup) {
                $identifiers[] = $group->identifier();
            }
        }

        sort($identifiers);

        return array_values(array_unique($identifiers));
    }
}
