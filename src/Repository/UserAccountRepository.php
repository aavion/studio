<?php

declare(strict_types=1);

namespace App\Repository;

use App\Core\Validation\EmailAddress;
use App\Entity\UserAccount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserAccount>
 */
final class UserAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserAccount::class);
    }

    public function findOneByEmail(string $email): ?UserAccount
    {
        $normalized = EmailAddress::normalize($email);

        if (!EmailAddress::isValid($normalized)) {
            return null;
        }

        $user = $this->createQueryBuilder('userAccount')
            ->andWhere('LOWER(userAccount.email) = :email')
            ->setParameter('email', $normalized)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $user instanceof UserAccount ? $user : null;
    }
}
