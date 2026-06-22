<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Entity\AclGroup;
use App\Entity\UserAccount;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

final readonly class DoctrineExtensionAclGroupMemberProvider implements ExtensionAclGroupMemberProviderInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return list<UserAccount>
     */
    public function members(AclGroup $group): array
    {
        try {
            $members = $this->entityManager
                ->getRepository(UserAccount::class)
                ->createQueryBuilder('user')
                ->innerJoin('user.groups', 'acl_group')
                ->andWhere('acl_group = :group')
                ->setParameter('group', $group)
                ->orderBy('user.username', 'ASC')
                ->getQuery()
                ->getResult();

            return array_values(array_filter($members, static fn (mixed $member): bool => $member instanceof UserAccount));
        } catch (Throwable) {
            return [];
        }
    }
}
