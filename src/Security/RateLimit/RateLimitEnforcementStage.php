<?php

declare(strict_types=1);

namespace App\Security\RateLimit;

enum RateLimitEnforcementStage
{
    case All;
    case SuspiciousProbe;
    case AuthenticationFailure;
    case Ordinary;

    public function consumesWebsiteFamily(): bool
    {
        return self::AuthenticationFailure !== $this;
    }
}
