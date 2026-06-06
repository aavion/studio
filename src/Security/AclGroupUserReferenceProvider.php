<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\AclGroup;
use App\Entity\UserAccount;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AclGroupUserReferenceProvider implements AclGroupReferenceProviderInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function key(): string
    {
        return 'users';
    }

    public function impact(AclGroup $group): array
    {
        $rows = [];

        foreach ($this->usersInGroup($group) as $user) {
            $rows[] = [
                'uid' => $user->uid(),
                'username' => $user->username(),
                'email' => $user->email(),
                'status' => $user->status()->value,
                'role' => $user->role()->value,
            ];
        }

        return $rows;
    }

    public function removeReferences(AclGroup $group): void
    {
        foreach ($this->usersInGroup($group) as $user) {
            $user->removeGroup($group);
        }
    }

    public function removeBelowMinRoleReferences(AclGroup $group, int $minRole): int
    {
        $removed = 0;

        foreach ($this->usersInGroup($group) as $user) {
            if ($user->accessLevel() >= $minRole) {
                continue;
            }

            $user->removeGroup($group);
            ++$removed;
        }

        return $removed;
    }

    /**
     * @return list<UserAccount>
     */
    private function usersInGroup(AclGroup $group): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('user')
            ->from(UserAccount::class, 'user')
            ->innerJoin('user.groups', 'aclGroup')
            ->andWhere('aclGroup = :group')
            ->setParameter('group', $group)
            ->orderBy('user.username', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
