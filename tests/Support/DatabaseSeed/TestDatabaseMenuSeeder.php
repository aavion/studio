<?php

declare(strict_types=1);

namespace App\Tests\Support\DatabaseSeed;

final class TestDatabaseMenuSeeder
{
    public static function seed(TestDatabaseSeedWriter $writer): void
    {
        $writer->insert('site_menu', [
            'uid' => '30000000-0000-0000-0000-000000000001',
            'identifier' => 'main',
            'labels' => $writer->json(['en' => 'Main navigation', 'de' => 'Hauptnavigation']),
            'active' => 1,
            'metadata' => $writer->json(['seed' => true]),
        ]);

        foreach ([
            ['30000000-0000-0000-0000-000000000101', 10, ['en' => 'Home', 'de' => 'Start'], '/'],
            ['30000000-0000-0000-0000-000000000102', 20, ['en' => 'About', 'de' => 'Ueber'], '/about'],
            ['30000000-0000-0000-0000-000000000103', 30, ['en' => 'News', 'de' => 'News'], '/news/first-update'],
        ] as [$uid, $sortOrder, $labels, $targetValue]) {
            $writer->insert('site_menu_item', [
                'uid' => $uid,
                'menu_uid' => '30000000-0000-0000-0000-000000000001',
                'parent_uid' => null,
                'sort_order' => $sortOrder,
                'labels' => $writer->json($labels),
                'target_type' => 'url',
                'target_value' => $targetValue,
                'view_min_level' => 0,
                'view_group_identifiers' => null,
                'metadata' => $writer->json(['seed' => true]),
            ]);
        }
    }

    private function __construct()
    {
    }
}
