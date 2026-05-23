<?php

declare(strict_types=1);

namespace App\Entity;

use App\Core\Message\MessageCode;
use App\Core\Message\MessageException;
use App\Core\Message\MessageKey;
use App\Core\Validation\Uid;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'user_account')]
#[ORM\UniqueConstraint(name: 'uniq_user_account_username', columns: ['username'])]
#[ORM\UniqueConstraint(name: 'uniq_user_account_email', columns: ['email'])]
class UserAccount
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $uid;

    #[ORM\Column(length: 80)]
    private string $username;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(length: 255)]
    private string $passwordHash;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $profile = [];

    /**
     * @var Collection<int, AclGroup>
     */
    #[ORM\ManyToMany(targetEntity: AclGroup::class)]
    #[ORM\JoinTable(name: 'user_acl_group')]
    #[ORM\JoinColumn(name: 'user_uid', referencedColumnName: 'uid', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'group_uid', referencedColumnName: 'uid', onDelete: 'CASCADE')]
    private Collection $groups;

    /**
     * @param array<string, mixed> $profile
     */
    public function __construct(string $uid, string $username, string $email, string $passwordHash, array $profile = [])
    {
        $this->uid = Uid::assert($uid, 'User UID');
        $this->username = self::assertUsername($username);
        $this->email = self::assertEmail($email);
        $this->passwordHash = $passwordHash;
        $this->profile = $profile;
        $this->groups = new ArrayCollection();
    }

    public function uid(): string
    {
        return $this->uid;
    }

    public function username(): string
    {
        return $this->username;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    /**
     * @return array<string, mixed>
     */
    public function profile(): array
    {
        return $this->profile;
    }

    /**
     * @return Collection<int, AclGroup>
     */
    public function groups(): Collection
    {
        return $this->groups;
    }

    public function addGroup(AclGroup $group): void
    {
        if (!$this->groups->contains($group)) {
            $this->groups->add($group);
        }
    }

    public function maxAccessLevel(): int
    {
        $max = 0;

        foreach ($this->groups as $group) {
            $max = max($max, $group->accessLevel());
        }

        return $max;
    }

    private static function assertUsername(string $username): string
    {
        if (1 !== preg_match('/^[a-z][a-z0-9_.-]{2,79}$/', $username)) {
            throw MessageException::forMessage(MessageCode::E_INVALID_ARGUMENT, MessageKey::USERNAME_INVALID, [
                '%username%' => $username,
            ]);
        }

        return $username;
    }

    private static function assertEmail(string $email): string
    {
        if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw MessageException::forMessage(MessageCode::E_INVALID_ARGUMENT, MessageKey::USER_EMAIL_INVALID, [
                '%email%' => $email,
            ]);
        }

        return $email;
    }
}
