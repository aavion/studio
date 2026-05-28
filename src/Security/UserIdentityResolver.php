<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\UserAccount;
use Doctrine\ORM\EntityManagerInterface;

final readonly class UserIdentityResolver
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function resolve(?string $uid): UserIdentity
    {
        if (null === $uid || '' === $uid) {
            return UserIdentity::deleted($uid);
        }

        $user = $this->entityManager->find(UserAccount::class, $uid);

        return $user instanceof UserAccount
            ? UserIdentity::fromUser($user)
            : UserIdentity::deleted($uid);
    }

    public function resolveUser(?UserAccount $user, ?string $uid = null): UserIdentity
    {
        return $user instanceof UserAccount
            ? UserIdentity::fromUser($user)
            : UserIdentity::deleted($uid);
    }
}
