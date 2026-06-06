<?php

declare(strict_types=1);

namespace App\Core\Access;

use App\Core\Access\AccessMessageKey;
use App\Core\Message\MessageException;
use App\Core\Validation\Identifier;

final readonly class AccessRule
{
    /**
     * @param list<string> $groupIdentifiers
     */
    private function __construct(
        private ?int $minLevel,
        private array $groupIdentifiers,
        private bool $inherited,
    ) {
    }

    public static function inherit(): self
    {
        return new self(null, [], true);
    }

    /**
     * @param list<string>|null $groupIdentifiers
     */
    public static function from(?int $minLevel, ?array $groupIdentifiers = null): self
    {
        $normalizedGroupIdentifiers = self::normalizeGroupIdentifiers($groupIdentifiers ?? []);

        if (null === $minLevel && [] === $normalizedGroupIdentifiers) {
            return self::inherit();
        }

        return new self(AccessLevel::assert($minLevel), $normalizedGroupIdentifiers, false);
    }

    public static function defaultFor(AccessCapability $capability): self
    {
        return new self($capability->defaultMinLevel(), [], false);
    }

    public function isInherited(): bool
    {
        return $this->inherited;
    }

    public function minLevel(): ?int
    {
        return $this->minLevel;
    }

    /**
     * @return list<string>
     */
    public function groupIdentifiers(): array
    {
        return $this->groupIdentifiers;
    }

    public function allows(AccessActor $actor): bool
    {
        if ($this->inherited) {
            return false;
        }

        if (null !== $this->minLevel && $actor->accessLevel() >= $this->minLevel) {
            return true;
        }

        foreach ($this->groupIdentifiers as $identifier) {
            if ($actor->hasGroupIdentifier($identifier)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{min_level: int|null, group_identifiers: list<string>, inherited: bool}
     */
    public function toArray(): array
    {
        return [
            'min_level' => $this->minLevel,
            'group_identifiers' => $this->groupIdentifiers,
            'inherited' => $this->inherited,
        ];
    }

    /**
     * @param list<string> $groupIdentifiers
     *
     * @return list<string>
     */
    public static function normalizeGroupIdentifiers(array $groupIdentifiers): array
    {
        foreach ($groupIdentifiers as $identifier) {
            if (!is_string($identifier)) {
                throw MessageException::invalidArgument(AccessMessageKey::ACCESS_GROUP_IDENTIFIER_INVALID, [
                    '%identifier%' => 'non-string',
                ]);
            }

            Identifier::assertAclGroupIdentifier($identifier);
        }

        sort($groupIdentifiers);

        return array_values(array_unique($groupIdentifiers));
    }

    /**
     * @param list<string>|null $groupIdentifiers
     *
     * @return list<string>|null
     */
    public static function normalizeGroupIdentifiersOrNull(?array $groupIdentifiers): ?array
    {
        return null === $groupIdentifiers ? null : self::normalizeGroupIdentifiers($groupIdentifiers);
    }
}
