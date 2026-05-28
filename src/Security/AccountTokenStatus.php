<?php

declare(strict_types=1);

namespace App\Security;

enum AccountTokenStatus: string
{
    case Pending = 'pending';
    case PendingApproval = 'pending_approval';
    case Used = 'used';
    case Revoked = 'revoked';

    public function isUsable(): bool
    {
        return self::Pending === $this;
    }
}
