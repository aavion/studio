<?php

declare(strict_types=1);

namespace App\Core\Extension;

use InvalidArgumentException;

enum ExtensionScope: string
{
    case FrontendTheme = 'frontend-theme';
    case BackendTheme = 'backend-theme';
    case SystemTemplate = 'system-template';
    case Module = 'module';
    case Api = 'api';
    case CaptchaProvider = 'captcha-provider';
    case EditorProvider = 'editor-provider';
    case Database = 'database';
    case ContentSchema = 'content-schema';
    case SchedulerTasks = 'scheduler-tasks';
    case Operations = 'operations';

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
                throw new InvalidArgumentException(sprintf('Invalid extension scope "%s".', $scope));
            }

            $scopes[$case->value] = $case;
        }

        if ([] === $scopes) {
            throw new InvalidArgumentException('Extension scope list must not be empty.');
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
            self::FrontendTheme, self::BackendTheme, self::SystemTemplate, self::CaptchaProvider, self::EditorProvider => true,
            self::Module, self::Api, self::Database, self::ContentSchema, self::SchedulerTasks, self::Operations => false,
        };
    }

    public function isProvider(): bool
    {
        return match ($this) {
            self::CaptchaProvider, self::EditorProvider => true,
            self::FrontendTheme, self::BackendTheme, self::SystemTemplate, self::Module, self::Api, self::Database, self::ContentSchema, self::SchedulerTasks, self::Operations => false,
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
                throw new InvalidArgumentException('Extension scope list must use matching square brackets.');
            }

            $value = substr($value, 1, -1);
        }

        return array_values(array_filter(array_map(
            static fn (string $scope): string => trim($scope, " \t\n\r\0\x0B'\""),
            explode(',', $value),
        ), static fn (string $scope): bool => '' !== $scope));
    }
}
