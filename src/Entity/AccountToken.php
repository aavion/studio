<?php

declare(strict_types=1);

namespace App\Entity;

use App\Core\Message\MessageException;
use App\Core\Message\MessageKey;
use App\Core\Validation\EmailAddress;
use App\Core\Validation\Identifier;
use App\Core\Validation\Uid;
use App\Security\AccountTokenStatus;
use App\Security\AccountTokenType;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'account_token')]
#[ORM\UniqueConstraint(name: 'uniq_account_token_hash', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_account_token_email', columns: ['email'])]
#[ORM\Index(name: 'idx_account_token_type_status', columns: ['type', 'status'])]
#[ORM\Index(name: 'idx_account_token_user_type', columns: ['user_uid', 'type'])]
#[ORM\Index(name: 'idx_account_token_expires_at', columns: ['expires_at'])]
class AccountToken
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $uid;

    #[ORM\Column(length: 64)]
    private string $tokenHash;

    #[ORM\Column(enumType: AccountTokenType::class)]
    private AccountTokenType $type;

    #[ORM\Column(enumType: AccountTokenStatus::class)]
    private AccountTokenStatus $status;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\ManyToOne(targetEntity: UserAccount::class)]
    #[ORM\JoinColumn(name: 'user_uid', referencedColumnName: 'uid', nullable: true, onDelete: 'CASCADE')]
    private ?UserAccount $user;

    /**
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $groupIdentifiers;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $metadata;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column]
    private DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $consumedAt = null;

    /**
     * @param list<string> $groupIdentifiers
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        string $uid,
        string $tokenHash,
        AccountTokenType $type,
        string $email,
        array $groupIdentifiers = [],
        ?UserAccount $user = null,
        AccountTokenStatus $status = AccountTokenStatus::Pending,
        ?DateTimeImmutable $createdAt = null,
        ?DateTimeImmutable $expiresAt = null,
        array $metadata = [],
    ) {
        $this->uid = Uid::assert($uid, 'Account token UID');
        $this->tokenHash = self::assertTokenHash($tokenHash);
        $this->type = $type;
        $this->email = self::assertEmail($email);
        $this->groupIdentifiers = self::assertGroupIdentifiers($groupIdentifiers);
        $this->user = $user;
        $this->status = $status;
        $this->createdAt = $createdAt ?? new DateTimeImmutable();
        $this->expiresAt = $expiresAt ?? $this->createdAt->modify('+24 hours');
        $this->metadata = $metadata;
    }

    public function uid(): string
    {
        return $this->uid;
    }

    public function tokenHash(): string
    {
        return $this->tokenHash;
    }

    public function type(): AccountTokenType
    {
        return $this->type;
    }

    public function status(): AccountTokenStatus
    {
        return $this->status;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function user(): ?UserAccount
    {
        return $this->user;
    }

    /**
     * @return list<string>
     */
    public function groupIdentifiers(): array
    {
        return $this->groupIdentifiers;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function consumedAt(): ?DateTimeImmutable
    {
        return $this->consumedAt;
    }

    /**
     * @param list<string> $groupIdentifiers
     */
    public function updateGroups(array $groupIdentifiers): void
    {
        $this->groupIdentifiers = self::assertGroupIdentifiers($groupIdentifiers);
    }

    public function approve(): void
    {
        if (AccountTokenStatus::PendingApproval === $this->status) {
            $this->status = AccountTokenStatus::Pending;
        }
    }

    public function rotateTokenHash(string $tokenHash, ?DateTimeImmutable $expiresAt = null): void
    {
        $this->tokenHash = self::assertTokenHash($tokenHash);
        $this->expiresAt = $expiresAt ?? (new DateTimeImmutable())->modify('+24 hours');
    }

    public function revoke(): void
    {
        if (AccountTokenStatus::Used !== $this->status) {
            $this->status = AccountTokenStatus::Revoked;
        }
    }

    public function consume(?UserAccount $user = null, ?DateTimeImmutable $now = null): void
    {
        $this->user = $user ?? $this->user;
        $this->status = AccountTokenStatus::Used;
        $this->consumedAt = $now ?? new DateTimeImmutable();
    }

    public function isExpired(?DateTimeImmutable $now = null): bool
    {
        return ($now ?? new DateTimeImmutable()) > $this->expiresAt;
    }

    private static function assertTokenHash(string $tokenHash): string
    {
        if (1 !== preg_match('/^[a-f0-9]{64}$/', $tokenHash)) {
            throw MessageException::invalidArgument(MessageKey::ACCOUNT_TOKEN_HASH_INVALID);
        }

        return $tokenHash;
    }

    private static function assertEmail(string $email): string
    {
        return EmailAddress::assert($email);
    }

    /**
     * @param list<string> $groupIdentifiers
     *
     * @return list<string>
     */
    private static function assertGroupIdentifiers(array $groupIdentifiers): array
    {
        $normalized = [];

        foreach ($groupIdentifiers as $identifier) {
            if (!is_string($identifier)) {
                throw MessageException::invalidArgument(MessageKey::ACCESS_GROUP_IDENTIFIER_INVALID, [
                    '%identifier%' => (string) $identifier,
                ]);
            }

            $identifier = Identifier::assertAclGroupIdentifier($identifier);
            $normalized[$identifier] = $identifier;
        }

        return array_values($normalized);
    }
}
