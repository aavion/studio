<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\User\UserInterface;

interface AccessLevelAwareUserInterface extends UserInterface
{
    public function accessLevel(): int;
}
