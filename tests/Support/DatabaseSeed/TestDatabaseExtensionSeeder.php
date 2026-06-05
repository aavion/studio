<?php

declare(strict_types=1);

namespace App\Tests\Support\DatabaseSeed;

final class TestDatabaseExtensionSeeder
{
    public static function seed(TestDatabaseSeedWriter $writer): void
    {
        $writer->insert('extension_package', [
            'uid' => '00000000-0000-7000-8000-000000000401',
            'package_scopes' => $writer->json(['frontend-theme', 'backend-theme', 'system-template']),
            'package_name' => 'system',
            'path' => '.',
            'manifest_version' => '1',
            'installed_version' => '0.1.0-dev',
            'status' => 'active',
            'metadata' => $writer->json(['preset' => true]),
            'modified_at' => TestDatabaseSeedWriter::NOW,
        ]);
    }

    private function __construct()
    {
    }
}
