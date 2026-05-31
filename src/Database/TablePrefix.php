<?php

declare(strict_types=1);

namespace App\Database;

final readonly class TablePrefix
{
    /**
     * @var list<string>
     */
    public const TABLES = [
        'access_statistic_event',
        'account_token',
        'acl_group',
        'api_key',
        'config_entry',
        'content_field_value',
        'content_item',
        'content_revision',
        'content_schema',
        'content_schema_version',
        'doctrine_migration_versions',
        'extension_package',
        'messenger_messages',
        'package_setting_entry',
        'site_menu',
        'site_menu_item',
        'state_marker',
        'user_account',
        'user_acl_group',
    ];

    public static function fromEnvironment(): string
    {
        $prefix = trim((string) ($_SERVER['APP_DATABASE_PREFIX'] ?? $_ENV['APP_DATABASE_PREFIX'] ?? ''));

        return 1 === preg_match('/^[a-z][a-z0-9_]*_$/', $prefix) ? $prefix : '';
    }

    public static function apply(string $name, ?string $prefix = null): string
    {
        $prefix ??= self::fromEnvironment();

        if ('' === $prefix || str_starts_with($name, $prefix)) {
            return $name;
        }

        if (!in_array($name, self::TABLES, true)) {
            return $name;
        }

        return $prefix.$name;
    }
}
