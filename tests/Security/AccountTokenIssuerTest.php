<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\AccountTokenIssuer;
use App\Security\AccountTokenType;
use App\Security\UserFlowConfig;
use PHPUnit\Framework\TestCase;

final class AccountTokenIssuerTest extends TestCase
{
    public function testItAppliesConfiguredTokenTtlAtIssueTime(): void
    {
        [$token] = (new AccountTokenIssuer())->issue(
            AccountTokenType::Invitation,
            'invitee@example.test',
            ttl: '+24 hours',
        );

        self::assertSame(
            $token->createdAt()->modify('+24 hours')->getTimestamp(),
            $token->expiresAt()->getTimestamp(),
        );
    }

    public function testPasswordResetTtlIsOneHour(): void
    {
        [$token] = (new AccountTokenIssuer())->issue(
            AccountTokenType::PasswordReset,
            'invitee@example.test',
            ttl: UserFlowConfig::PASSWORD_RESET_TTL,
        );

        self::assertSame(
            $token->createdAt()->modify('+1 hour')->getTimestamp(),
            $token->expiresAt()->getTimestamp(),
        );
    }
}
