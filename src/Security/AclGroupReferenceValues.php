<?php

declare(strict_types=1);

namespace App\Security;

final readonly class AclGroupReferenceValues
{
    /**
     * @param list<string>|null $values
     *
     * @return list<string>
     */
    public function fieldIfContains(string $field, ?array $values, string $identifier): array
    {
        return in_array($identifier, $values ?? [], true) ? [$field] : [];
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    public function withoutIdentifier(array $values, string $identifier): array
    {
        return array_values(array_filter($values, static fn (string $value): bool => $value !== $identifier));
    }

    /**
     * @param list<string>|null $values
     *
     * @return list<string>|null
     */
    public function withoutIdentifierOrNull(?array $values, string $identifier): ?array
    {
        if (null === $values) {
            return null;
        }

        return $this->withoutIdentifier($values, $identifier);
    }
}
