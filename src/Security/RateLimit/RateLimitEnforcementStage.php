<?php

declare(strict_types=1);

namespace App\Security\RateLimit;

use App\Security\Abuse\ActionCost;

enum RateLimitEnforcementStage
{
    case All;
    case SuspiciousProbe;
    case AuthenticationFailure;
    case Ordinary;

    public function handlesCost(ActionCost $cost): bool
    {
        return match ($this) {
            self::All => true,
            self::SuspiciousProbe => false,
            self::AuthenticationFailure => in_array($cost->bucketFamily(), [
                'login',
                'recovery_login',
                'api_read',
                'api_public_read',
                'api_write',
                'admin_mutation',
                'upload_archive',
                'download_diagnostics',
            ], true),
            self::Ordinary => !in_array($cost->bucketFamily(), [
                'login',
                'recovery_login',
            ], true),
        };
    }

    public function consumesWebsiteFamily(): bool
    {
        return self::AuthenticationFailure !== $this;
    }
}
