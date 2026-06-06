<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Message\MessageException;

final readonly class ExtensionPackageIdentity
{
    public static function assertPackageName(string $packageName): string
    {
        if (1 !== preg_match('/^[a-z0-9][a-z0-9_.\/-]*$/', $packageName)) {
            throw MessageException::invalidArgument(PackageMessageKey::PACKAGE_IDENTIFIER_INVALID, [
                '%identifier%' => $packageName,
            ]);
        }

        return $packageName;
    }

    /**
     * @param list<PackageScope|string> $scopes
     *
     * @return list<string>
     */
    public static function normalizeScopes(array $scopes): array
    {
        $normalized = [];

        foreach ($scopes as $scope) {
            $case = $scope instanceof PackageScope ? $scope : PackageScope::tryFrom($scope);
            if (null === $case) {
                throw MessageException::invalidArgument(PackageMessageKey::PACKAGE_SCOPE_INVALID, [
                    '%scope%' => is_scalar($scope) ? (string) $scope : get_debug_type($scope),
                ]);
            }

            $normalized[$case->value] = $case->value;
        }

        if ([] === $normalized) {
            throw MessageException::invalidArgument(PackageMessageKey::PACKAGE_SCOPE_INVALID, [
                '%scope%' => '',
            ]);
        }

        return array_values($normalized);
    }
}
