<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Validation\IdentifierSpec;
use App\Core\Message\MessageException;

final readonly class ExtensionIdentity
{
    public const MAX_EXTENSION_NAME_LENGTH = IdentifierSpec::MAX_SLUG_LENGTH;
    public const EXTENSION_NAME_PATTERN = IdentifierSpec::OWNER_SLUG_PATTERN;

    public static function assertExtensionName(string $extensionName): string
    {
        if (!self::isExtensionName($extensionName)) {
            throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_IDENTIFIER_INVALID, [
                '%identifier%' => $extensionName,
            ]);
        }

        return $extensionName;
    }

    public static function isExtensionName(string $extensionName): bool
    {
        return IdentifierSpec::isOwnerSlug($extensionName);
    }

    /**
     * @param list<ExtensionScope|string> $scopes
     *
     * @return list<string>
     */
    public static function normalizeScopes(array $scopes): array
    {
        $normalized = [];

        foreach ($scopes as $scope) {
            $case = $scope instanceof ExtensionScope ? $scope : ExtensionScope::tryFrom($scope);
            if (null === $case) {
                throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_SCOPE_INVALID, [
                    '%scope%' => is_scalar($scope) ? (string) $scope : get_debug_type($scope),
                ]);
            }

            $normalized[$case->value] = $case->value;
        }

        if ([] === $normalized) {
            throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_SCOPE_INVALID, [
                '%scope%' => '',
            ]);
        }

        return array_values($normalized);
    }
}
