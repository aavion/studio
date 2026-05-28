<?php

declare(strict_types=1);

namespace App\Entity;

use App\Core\Message\MessageException;
use App\Core\Message\MessageKey;
use App\Core\Validation\EmailAddress;
use App\Core\Validation\Uid;
use App\Repository\UserAccountRepository;
use App\Security\AccessLevelAwareUserInterface;
use App\Security\UserAccountStatus;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

#[ORM\Entity(repositoryClass: UserAccountRepository::class)]
#[ORM\Table(name: 'user_account')]
#[ORM\UniqueConstraint(name: 'uniq_user_account_username', columns: ['username'])]
#[ORM\UniqueConstraint(name: 'uniq_user_account_email', columns: ['email'])]
#[ORM\Index(name: 'idx_user_account_status', columns: ['status'])]
class UserAccount implements AccessLevelAwareUserInterface, PasswordAuthenticatedUserInterface
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
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $settings = ['language' => 'default'];

    #[ORM\Column(enumType: UserAccountStatus::class)]
    private UserAccountStatus $status = UserAccountStatus::Active;

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
    public function __construct(
        string $uid,
        string $username,
        string $email,
        string $passwordHash,
        array $profile = [],
        array $settings = ['language' => 'default'],
        UserAccountStatus $status = UserAccountStatus::Active,
    ) {
        $this->uid = Uid::assert($uid, 'User UID');
        $this->username = self::assertUsername($username);
        $this->email = self::assertEmail($email);
        $this->passwordHash = $passwordHash;
        $this->profile = $profile;
        $this->settings = $settings;
        $this->status = $status;
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

    public function changeUsername(string $username): void
    {
        $this->username = self::assertUsername($username);
    }

    public function email(): string
    {
        return $this->email;
    }

    public function changeEmail(string $email): void
    {
        $this->email = self::assertEmail($email);
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return [];
    }

    public function getUserIdentifier(): string
    {
        return $this->username;
    }

    /**
     * @return array<string, mixed>
     */
    public function profile(): array
    {
        return $this->profile;
    }

    /**
     * @param array<string, mixed> $profile
     */
    public function updateProfile(array $profile): void
    {
        $this->profile = $profile;
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return $this->settings;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function updateSettings(array $settings): void
    {
        $this->settings = $settings;
    }

    public function status(): UserAccountStatus
    {
        return $this->status;
    }

    public function changePassword(string $passwordHash): void
    {
        $this->passwordHash = $passwordHash;
    }

    public function changeStatus(UserAccountStatus $status): void
    {
        $this->status = $status;
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

    public function removeGroup(AclGroup $group): void
    {
        $this->groups->removeElement($group);
    }

    public function clearGroups(): void
    {
        $this->groups->clear();
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
        if (1 !== preg_match('/^[A-Za-z][A-Za-z0-9_-]{4,29}$/', $username)) {
            throw MessageException::invalidArgument(MessageKey::USERNAME_INVALID, [
                '%username%' => $username,
            ]);
        }

        return $username;
    }

    private static function assertEmail(string $email): string
    {
        return EmailAddress::assert($email);
    }
}
