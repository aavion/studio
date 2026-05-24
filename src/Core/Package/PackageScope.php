<?php

declare(strict_types=1);

namespace App\Core\Package;

use InvalidArgumentException;

enum PackageScope: string
{
    case FrontendTheme = 'frontend-theme';
    case BackendTheme = 'backend-theme';
    case Module = 'module';
    case CaptchaProvider = 'captcha-provider';
    case EditorProvider = 'editor-provider';

    /**
     * @return list<self>
     */
    public static function fromManifestValue(string $value): array
    {
        $values = self::parseList($value);
        $scopes = [];

        foreach ($values as $scope) {
            $case = self::tryFrom($scope);
            if (null === $case) {
                throw new InvalidArgumentException(sprintf('Invalid package scope "%s".', $scope));
            }

            $scopes[$case->value] = $case;
        }

        if ([] === $scopes) {
            throw new InvalidArgumentException('Package scope list must not be empty.');
        }

        return array_values($scopes);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $scope): string => $scope->value, self::cases());
    }

    public function isSingleActive(): bool
    {
        return match ($this) {
            self::FrontendTheme, self::BackendTheme, self::CaptchaProvider, self::EditorProvider => true,
            self::Module => false,
        };
    }

    /**
     * @return list<string>
     */
    private static function parseList(string $value): array
    {
        $value = trim($value);

        if ('' === $value) {
            return [];
        }

        if (str_starts_with($value, '[') || str_ends_with($value, ']')) {
            if (!str_starts_with($value, '[') || !str_ends_with($value, ']')) {
                throw new InvalidArgumentException('Package scope list must use matching square brackets.');
            }

            $value = substr($value, 1, -1);
        }

        return array_values(array_filter(array_map(
            static fn (string $scope): string => trim($scope, " \t\n\r\0\x0B'\""),
            explode(',', $value),
        ), static fn (string $scope): bool => '' !== $scope));
    }
}
