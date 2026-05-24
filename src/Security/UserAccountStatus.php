<?php

declare(strict_types=1);

namespace App\Security;

enum UserAccountStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Deleted = 'deleted';

    public function isUsable(): bool
    {
        return self::Active === $this;
    }
}
