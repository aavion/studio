<?php

declare(strict_types=1);

namespace App\Database;

use App\Core\Validation\IdentifierSpec;

final readonly class TablePrefix
{
    public const MIGRATION_TABLE = 'doctrine_migration_versions';

    /**
     * @var list<string>
     */
    public const TABLES = [
        'access_log_entry',
        'access_statistic_event',
        'account_token',
        'acl_group',
        'api_key',
        'audit_log_entry',
        'config_entry',
        'content_field_value',
        'content_item',
        'content_revision',
        'content_schema',
        'content_schema_version',
        'extension',
        'messenger_messages',
        'message_log_entry',
        'extension_setting_entry',
        'scheduler_task',
        'scheduler_task_run',
        'security_signal_event',
        'site_menu',
        'site_menu_item',
        'state_marker',
        'ui_alert_inbox',
        'user_account',
        'user_acl_group',
    ];

    public static function fromEnvironment(): string
    {
        $prefix = trim((string) ($_SERVER['APP_DATABASE_PREFIX'] ?? $_ENV['APP_DATABASE_PREFIX'] ?? ''));

        return self::normalize($prefix);
    }

    public static function normalize(?string $prefix): string
    {
        $prefix = trim((string) $prefix);

        if ('' === $prefix) {
            return '';
        }

        return IdentifierSpec::isDatabaseTablePrefix($prefix) ? $prefix : '';
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
