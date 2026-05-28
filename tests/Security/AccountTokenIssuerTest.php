<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Core\Message\MessageKey;
use App\Security\AccountTokenIssuer;
use App\Security\AccountTokenType;
use App\Security\UserFlowConfig;
use InvalidArgumentException;
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

    public function testItReissuesExistingTokensWithNewHashAndExpiry(): void
    {
        $issuer = new AccountTokenIssuer();
        [$token] = $issuer->issue(
            AccountTokenType::Invitation,
            'invitee@example.test',
            ttl: '-1 hour',
        );
        $originalHash = $token->tokenHash();
        $originalExpiry = $token->expiresAt();

        $plainToken = $issuer->reissue($token, '+24 hours');

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $plainToken);
        self::assertNotSame($originalHash, $token->tokenHash());
        self::assertGreaterThan($originalExpiry, $token->expiresAt());
        self::assertSame($issuer->hash($plainToken), $token->tokenHash());
    }

    public function testItRejectsShortAclGroupIdentifiers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(MessageKey::ACCESS_GROUP_IDENTIFIER_INVALID);

        (new AccountTokenIssuer())->issue(
            AccountTokenType::Invitation,
            'invitee@example.test',
            ['ab'],
        );
    }
}
