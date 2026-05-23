<?php

declare(strict_types=1);

namespace App\Entity;

use App\Core\Message\MessageCode;
use App\Core\Message\MessageException;
use App\Core\Message\MessageKey;
use App\Core\Validation\Uid;
use App\Security\ApiKeyStatus;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'api_key')]
#[ORM\UniqueConstraint(name: 'uniq_api_key_hash', columns: ['key_hash'])]
#[ORM\Index(name: 'idx_api_key_user_status', columns: ['user_uid', 'status'])]
class ApiKey
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $uid;

    #[ORM\Column(length: 255)]
    private string $keyHash;

    #[ORM\Column(length: 16)]
    private string $keyPrefix;

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
        string $keyHash,
        string $keyPrefix,
        UserAccount $user,
        ApiKeyStatus $status = ApiKeyStatus::ReadOnly,
        ?DateTimeImmutable $createdAt = null,
    ) {
        $this->uid = Uid::assert($uid, 'API key UID');
        $this->keyHash = $keyHash;
        $this->keyPrefix = self::assertKeyPrefix($keyPrefix);
        $this->user = $user;
        $this->status = $status;
        $this->createdAt = $createdAt ?? new DateTimeImmutable();
    }

    public function uid(): string
    {
        return $this->uid;
    }

    public function keyPrefix(): string
    {
        return $this->keyPrefix;
    }

    public function status(): ApiKeyStatus
    {
        return $this->status;
    }

    public function revoke(?DateTimeImmutable $revokedAt = null): void
    {
        $this->status = ApiKeyStatus::Revoked;
        $this->revokedAt = $revokedAt ?? new DateTimeImmutable();
    }

    private static function assertKeyPrefix(string $keyPrefix): string
    {
        if (1 !== preg_match('/^[A-Za-z0-9_-]{4,16}$/', $keyPrefix)) {
            throw MessageException::forMessage(MessageCode::E_INVALID_ARGUMENT, MessageKey::API_KEY_PREFIX_INVALID, [
                '%prefix%' => $keyPrefix,
            ]);
        }

        return $keyPrefix;
    }
}
