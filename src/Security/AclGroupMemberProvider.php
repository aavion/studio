<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\AclGroup;
use App\Entity\UserAccount;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AclGroupMemberProvider
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function count(AclGroup $group): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM user_acl_group WHERE group_uid = ?',
            [$group->uid()],
        );
    }

    /**
     * @return list<UserAccount>
     */
    public function members(AclGroup $group): array
    {
        return array_values(array_filter(
            $this->entityManager->createQueryBuilder()
                ->select('user')
                ->from(UserAccount::class, 'user')
                ->innerJoin('user.groups', 'aclGroup')
                ->andWhere('aclGroup = :group')
                ->setParameter('group', $group)
                ->orderBy('user.username', 'ASC')
                ->getQuery()
                ->getResult(),
            static fn (mixed $user): bool => $user instanceof UserAccount,
        ));
    }
}
