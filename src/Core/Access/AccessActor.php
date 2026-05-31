<?php

declare(strict_types=1);

namespace App\Core\Access;

use App\Core\Validation\Identifier;
use App\Entity\UserAccount;

final readonly class AccessActor
{
    /**
     * @param list<string> $groupIdentifiers
     */
    private function __construct(
        private ?string $userUid,
        private ?string $username,
        private int $accessLevel,
        private array $groupIdentifiers,
    ) {
    }

    public static function anonymous(): self
    {
        return new self(null, null, AccessLevel::PUBLIC, []);
    }

    public static function fromUserAccount(UserAccount $user): self
    {
        $groupIdentifiers = [];

        foreach ($user->groups() as $group) {
            if ($user->accessLevel() >= $group->minRole()) {
                $groupIdentifiers[] = $group->identifier();
            }
        }

        return new self($user->uid(), $user->username(), $user->accessLevel(), self::normalizeGroupIdentifiers($groupIdentifiers));
    }

    /**
     * @param list<string> $groupIdentifiers
     */
    public static function fromAccess(int $accessLevel, array $groupIdentifiers = [], ?string $userUid = null, ?string $username = null): self
    {
        return new self($userUid, $username, AccessLevel::assert($accessLevel), self::normalizeGroupIdentifiers($groupIdentifiers));
    }

    public function userUid(): ?string
    {
        return $this->userUid;
    }

    public function username(): ?string
    {
        return $this->username;
    }

    public function accessLevel(): int
    {
        return $this->accessLevel;
    }

    /**
     * @return list<string>
     */
    public function groupIdentifiers(): array
    {
        return $this->groupIdentifiers;
    }

    public function hasGroupIdentifier(string $identifier): bool
    {
        return in_array($identifier, $this->groupIdentifiers, true);
    }

    /**
     * @return array{user_uid: string|null, username: string|null, access_level: int, group_identifiers: list<string>}
     */
    public function toContext(): array
    {
        return [
            'user_uid' => $this->userUid,
            'username' => $this->username,
            'access_level' => $this->accessLevel,
            'group_identifiers' => $this->groupIdentifiers,
        ];
    }

    /**
     * @param list<string> $groupIdentifiers
     *
     * @return list<string>
     */
    private static function normalizeGroupIdentifiers(array $groupIdentifiers): array
    {
        foreach ($groupIdentifiers as $identifier) {
            Identifier::assertAclGroupIdentifier($identifier);
        }

        sort($groupIdentifiers);

        return array_values(array_unique($groupIdentifiers));
    }
}
