<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\UserAccount;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class UserAccountChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        $this->assertUsable($user);
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        $this->assertUsable($user);
    }

    private function assertUsable(UserInterface $user): void
    {
        if (!$user instanceof UserAccount || $user->status()->isUsable()) {
            return;
        }

        throw new DisabledException();
    }
}
