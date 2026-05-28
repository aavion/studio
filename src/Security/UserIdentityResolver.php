<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\UserAccount;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class UserIdentityResolver
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TranslatorInterface $translator,
    ) {
    }

    public function resolve(?string $uid, ?string $locale = null): UserIdentity
    {
        if (null === $uid || '' === $uid) {
            return $this->deletedIdentity($uid, $locale);
        }

        $user = $this->entityManager->find(UserAccount::class, $uid);

        return $user instanceof UserAccount
            ? UserIdentity::fromUser($user)
            : $this->deletedIdentity($uid, $locale);
    }

    public function resolveUser(?UserAccount $user, ?string $uid = null, ?string $locale = null): UserIdentity
    {
        return $user instanceof UserAccount
            ? UserIdentity::fromUser($user)
            : $this->deletedIdentity($uid, $locale);
    }

    private function deletedIdentity(?string $uid, ?string $locale): UserIdentity
    {
        return UserIdentity::deleted($uid, $this->translator->trans('ui.user.identity.deleted', locale: $locale));
    }
}
