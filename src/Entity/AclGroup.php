<?php

declare(strict_types=1);

namespace App\Entity;

use App\Core\Access\AccessLevel;
use App\Core\Message\MessageKey;
use App\Core\Validation\Identifier;
use App\Core\Validation\Uid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'acl_group')]
#[ORM\UniqueConstraint(name: 'uniq_acl_group_identifier', columns: ['identifier'])]
#[ORM\Index(name: 'idx_acl_group_access_level', columns: ['access_level'])]
class AclGroup
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $uid;

    #[ORM\Column(length: 80)]
    private string $identifier;

    /**
     * @var array<string, string>
     */
    #[ORM\Column(type: 'json')]
    private array $name;

    #[ORM\Column]
    private int $accessLevel;

    #[ORM\Column]
    private bool $locked;

    #[ORM\Column]
    private bool $allowEmpty;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $metadata = [];

    /**
     * @param array<string, string> $name
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        string $uid,
        string $identifier,
        array $name,
        int $accessLevel,
        bool $locked = false,
        bool $allowEmpty = true,
        array $metadata = [],
    ) {
        $this->uid = Uid::assert($uid, 'ACL group UID');
        $this->identifier = Identifier::assertSnakeCase($identifier, MessageKey::ACCESS_GROUP_IDENTIFIER_INVALID, '%identifier%');
        $this->name = $name;
        $this->accessLevel = AccessLevel::assert($accessLevel);
        $this->locked = $locked;
        $this->allowEmpty = $allowEmpty;
        $this->metadata = $metadata;
    }

    public function uid(): string
    {
        return $this->uid;
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    /**
     * @return array<string, string>
     */
    public function name(): array
    {
        return $this->name;
    }

    public function accessLevel(): int
    {
        return $this->accessLevel;
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }

    public function allowsEmptyMembership(): bool
    {
        return $this->allowEmpty;
    }

    /**
     * @param array<string, string> $name
     */
    public function rename(array $name): void
    {
        $this->name = $name;
    }

    public function changeAccessLevel(int $accessLevel): void
    {
        $this->accessLevel = AccessLevel::assert($accessLevel);
    }

    public function changeEmptyMembershipPolicy(bool $allowEmpty): void
    {
        $this->allowEmpty = $allowEmpty;
    }
}
