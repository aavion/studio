<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Id\UuidFactory;
use App\Entity\AccountToken;
use App\Entity\UserAccount;
use DateTimeImmutable;

final readonly class AccountTokenIssuer
{
    public function __construct(private UuidFactory $uuidFactory = new UuidFactory())
    {
    }

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
        UserRole $role = UserRole::User,
        AccountTokenStatus $status = AccountTokenStatus::Pending,
        string $ttl = '+7 days',
        array $metadata = [],
    ): array {
        $plainToken = bin2hex(random_bytes(32));
        $now = new DateTimeImmutable();

        return [
            new AccountToken(
                $this->uuidFactory->generate(),
                $this->hash($plainToken),
                $type,
                $email,
                $groupIdentifiers,
                $user,
                $role,
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
}
