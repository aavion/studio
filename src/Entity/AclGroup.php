<?php

declare(strict_types=1);

namespace App\Entity;

use App\Core\Access\AccessLevel;
use App\Core\Access\AccessMessageKey;
use App\Core\Message\MessageException;
use App\Core\Validation\Identifier;
use App\Core\Validation\Uid;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'acl_group')]
#[ORM\UniqueConstraint(name: 'uniq_acl_group_identifier', columns: ['identifier'])]
#[ORM\Index(name: 'idx_acl_group_min_role', columns: ['min_role'])]
class AclGroup
{
    public const MAX_NAME_LENGTH = 160;

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $uid;

    #[ORM\Column(length: 80)]
    private string $identifier;

    #[ORM\Column(length: self::MAX_NAME_LENGTH)]
    private string $name;

    #[ORM\Column]
    private int $minRole;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $metadata = [];

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        string $uid,
        string $identifier,
        string $name,
        int $minRole,
        array $metadata = [],
    ) {
        $this->uid = Uid::assert($uid, 'ACL group UID');
        $this->identifier = Identifier::assertAclGroupIdentifier($identifier);
        $this->name = $this->normalizeName($name);
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

    public function name(): string
    {
        return $this->name;
    }

    public function minRole(): int
    {
        return $this->minRole;
    }

    public function rename(string $name): void
    {
        $this->name = $this->normalizeName($name);
    }

    public function changeMinRole(int $minRole): void
    {
        $this->minRole = AccessLevel::assert($minRole);
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);

        if ('' === $name || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw MessageException::invalidArgument(AccessMessageKey::ACCESS_GROUP_NAME_INVALID, [
                '%name%' => $name,
            ]);
        }

        return $name;
    }
}
