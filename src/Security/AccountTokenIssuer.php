<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\AccountToken;
use App\Entity\UserAccount;
use DateTimeImmutable;

final readonly class AccountTokenIssuer
{
    /**
     * @param list<string> $groupIdentifiers
     * @param array<string, mixed> $metadata
     *
     * @return array{0: AccountToken, 1: string}
     */
    public function issue(
        AccountTokenType $type,
        string $email,
        array $groupIdentifiers = [],
        ?UserAccount $user = null,
        AccountTokenStatus $status = AccountTokenStatus::Pending,
        string $ttl = '+7 days',
        array $metadata = [],
    ): array {
        $plainToken = bin2hex(random_bytes(32));
        $now = new DateTimeImmutable();

        return [
            new AccountToken(
                self::uuid(),
                $this->hash($plainToken),
                $type,
                $email,
                $groupIdentifiers,
                $user,
                $status,
                $now,
                $now->modify($ttl),
                $metadata,
            ),
            $plainToken,
        ];
    }

    public function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public function reissue(AccountToken $token, string $ttl): string
    {
        $plainToken = bin2hex(random_bytes(32));
        $token->rotateTokenHash($this->hash($plainToken), (new DateTimeImmutable())->modify($ttl));

        return $plainToken;
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20),
        );
    }
}
