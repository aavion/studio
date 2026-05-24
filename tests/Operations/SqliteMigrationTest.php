<?php

declare(strict_types=1);

namespace App\Tests\Operations;

use App\Tests\Support\FilesystemTestHelper;
use PDO;
use PHPUnit\Framework\TestCase;

final class SqliteMigrationTest extends TestCase
{
    use FilesystemTestHelper;

    public function testMigrationsApplyToConfiguredSqliteDatabase(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required for SQLite migration verification.');
        }

        $databasePath = $this->projectRoot().'/var/test/test.db';

        self::assertFileExists($databasePath);

        $pdo = new PDO('sqlite:'.$databasePath);
        $tables = $pdo
            ->query("SELECT name FROM sqlite_master WHERE type = 'table'")
            ->fetchAll(PDO::FETCH_COLUMN);

        self::assertContains('doctrine_migration_versions', $tables);
        self::assertContains('messenger_messages', $tables);
        self::assertContains('config_entry', $tables);
        self::assertContains('state_marker', $tables);
        self::assertContains('content_schema', $tables);
        self::assertContains('content_revision', $tables);
        self::assertContains('content_field_value', $tables);
        self::assertContains('user_account', $tables);
        self::assertContains('api_key', $tables);
        self::assertContains('site_menu_item', $tables);
    }
}
