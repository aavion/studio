<?php

declare(strict_types=1);

namespace App\Tests\Support\DatabaseSeed;

final class TestDatabaseConfigSeeder
{
    public static function seed(TestDatabaseSeedWriter $writer): void
    {
        $entries = [
            ['user.default_acl_group', 'registered', 'string'],
            ['content.default_locale', 'en', 'string'],
            ['localization.default_language', 'en', 'string'],
            ['localization.route_prefixes_enabled', false, 'boolean'],
            ['content.home_path', '/home', 'string'],
            ['content.enabled_locales', ['en', 'de'], 'json'],
            ['content.default_variant', 'default', 'string'],
            ['content.revision_retention_count', 10, 'integer'],
        ];

        foreach ($entries as [$key, $value, $type]) {
            $writer->insert('config_entry', [
                'config_key' => $key,
                'value' => $writer->json($value),
                'value_type' => $type,
                'sensitive' => 0,
                'modified_at' => TestDatabaseSeedWriter::NOW,
                'modified_by' => 'system',
            ]);
        }
    }

    private function __construct()
    {
    }
}
