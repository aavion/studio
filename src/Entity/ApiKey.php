<?php

declare(strict_types=1);

namespace App\Entity;

use App\Core\Message\MessageException;
use App\Core\Message\MessageKey;
use App\Core\Validation\Uid;
use App\Security\ApiKeyStatus;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'api_key')]
#[ORM\UniqueConstraint(name: 'uniq_api_key_hmac_hash', columns: ['hmac_hash'])]
#[ORM\Index(name: 'idx_api_key_prefix', columns: ['prefix'])]
#[ORM\Index(name: 'idx_api_key_user_status', columns: ['user_uid', 'status'])]
class ApiKey
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $uid;

    #[ORM\Column(length: 16)]
    private string $prefix;

    #[ORM\Column(length: 64)]
    private string $hmacHash;

    #[ORM\Column(type: 'text')]
    private string $encryptedKey;

    #[ORM\ManyToOne(targetEntity: UserAccount::class)]
    #[ORM\JoinColumn(name: 'user_uid', referencedColumnName: 'uid', nullable: false, onDelete: 'CASCADE')]
    private UserAccount $user;

    #[ORM\Column(enumType: ApiKeyStatus::class)]
    private ApiKeyStatus $status;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $revokedAt = null;

    public function __construct(
        string $uid,
        string $prefix,
        string $hmacHash,
        string $encryptedKey,
        UserAccount $user,
        ApiKeyStatus $status = ApiKeyStatus::ReadOnly,
        ?DateTimeImmutable $createdAt = null,
    ) {
        $this->uid = Uid::assert($uid, 'API key UID');
        $this->prefix = self::assertPrefix($prefix);
        $this->hmacHash = self::assertHmacHash($hmacHash);
        $this->encryptedKey = self::assertEncryptedKey($encryptedKey);
        $this->user = $user;
        $this->status = $status;
        $this->createdAt = $createdAt ?? new DateTimeImmutable();
    }

    public function uid(): string
    {
        return $this->uid;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function hmacHash(): string
    {
        return $this->hmacHash;
    }

    public function encryptedKey(): string
    {
        return $this->encryptedKey;
    }

    public function status(): ApiKeyStatus
    {
        return $this->status;
    }

    public function user(): UserAccount
    {
        return $this->user;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function revokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revoke(?DateTimeImmutable $revokedAt = null): void
    {
        $this->status = ApiKeyStatus::Revoked;
        $this->revokedAt = $revokedAt ?? new DateTimeImmutable();
    }

    private static function assertPrefix(string $prefix): string
    {
        if (1 !== preg_match('/^[A-Za-z0-9_-]{4,16}$/', $prefix)) {
            throw MessageException::invalidArgument(MessageKey::API_KEY_PREFIX_INVALID, [
                '%prefix%' => $prefix,
            ]);
        }

        return $prefix;
    }

    private static function assertHmacHash(string $hmacHash): string
    {
        if (1 !== preg_match('/^[a-f0-9]{64}$/', $hmacHash)) {
            throw MessageException::invalidArgument(MessageKey::API_KEY_HMAC_HASH_INVALID);
        }

        return $hmacHash;
    }

    private static function assertEncryptedKey(string $encryptedKey): string
    {
        if ('' === trim($encryptedKey)) {
            throw MessageException::invalidArgument(MessageKey::API_KEY_ENCRYPTED_KEY_EMPTY);
        }

        return $encryptedKey;
    }
}
