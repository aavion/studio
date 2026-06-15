<?php

declare(strict_types=1);

namespace App\View\Alert;

use App\Entity\UserAccount;
use App\Repository\UserAccountRepository;
use Throwable;

final readonly class DoctrineUiAlertUserIdentityResolver implements UiAlertUserIdentityResolverInterface
{
    public function __construct(private UserAccountRepository $users)
    {
    }

    public function resolveUid(string $identifier): ?string
    {
        $identifier = trim($identifier);
        if ('' === $identifier || !UserAccount::isValidUsername($identifier)) {
            return null;
        }

        try {
            $user = $this->users->findOneBy(['username' => $identifier]);
        } catch (Throwable) {
            return null;
        }

        return $user instanceof UserAccount ? $user->uid() : null;
    }
}
