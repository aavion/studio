<?php

declare(strict_types=1);

namespace App\Backend;

use App\Core\Access\AccessActor;
use App\Core\Access\AccessCapability;
use App\Core\Access\AccessDecision;
use App\Core\Access\AccessResolver;
use App\Entity\UserAccount;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class BackendAccessGuard
{
    public function __construct(private AccessResolver $accessResolver)
    {
    }

    public function decide(BackendArea $area, ?UserInterface $user): AccessDecision
    {
        $actor = $user instanceof UserAccount ? AccessActor::fromUserAccount($user) : AccessActor::anonymous();

        return $this->accessResolver->decide(
            $actor,
            AccessCapability::Use,
            $area->accessRule(),
        );
    }
}
