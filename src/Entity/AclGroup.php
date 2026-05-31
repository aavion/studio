<?php

declare(strict_types=1);

namespace App\Entity;

use App\Core\Access\AccessLevel;
use App\Core\Validation\Identifier;
use App\Core\Validation\Uid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'acl_group')]
#[ORM\UniqueConstraint(name: 'uniq_acl_group_identifier', columns: ['identifier'])]
#[ORM\Index(name: 'idx_acl_group_min_role', columns: ['min_role'])]
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
    private int $minRole;

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
        int $minRole,
        array $metadata = [],
    ) {
        $this->uid = Uid::assert($uid, 'ACL group UID');
        $this->identifier = Identifier::assertAclGroupIdentifier($identifier);
        $this->name = $name;
        $this->minRole = AccessLevel::assert($minRole);
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

    public function minRole(): int
    {
        return $this->minRole;
    }

    /**
     * @param array<string, string> $name
     */
    public function rename(array $name): void
    {
        $this->name = $name;
    }

    public function changeMinRole(int $minRole): void
    {
        $this->minRole = AccessLevel::assert($minRole);
    }
}
