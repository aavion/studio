<?php

declare(strict_types=1);

namespace App\Tests\Support\DatabaseSeed;

use App\Setup\DatabaseDriver;
use App\Setup\SetupDefaultSeed;
use App\Setup\SetupInput;

final class TestDatabaseConfigSeeder
{
    public static function seed(TestDatabaseSeedWriter $writer): void
    {
        $setupSeed = new SetupDefaultSeed();
        $setupEntries = array_map(
            static fn (array $entry): array => [$entry['key'], $entry['value'], $entry['type']->value],
            $setupSeed->configEntries(new SetupInput(
                appEnv: 'test',
                language: 'en',
                siteTitle: 'Test Studio',
                defaultUri: 'https://example.test',
                databaseDriver: DatabaseDriver::SQLite,
                databaseUrl: 'sqlite:///%kernel.project_dir%/var/test/test.db',
                adminUsername: 'admin',
                adminPassword: (string) ($_SERVER['APP_SECRET'] ?? 'test-secret'),
                adminEmail: 'admin@example.test',
            )),
        );
        $entries = [
            ...$setupEntries,
            ['content.default_locale', 'en', 'string'],
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
