<?php

declare(strict_types=1);

namespace App\Tests\Operations;

use App\Tests\Support\FilesystemTestHelper;
use PDO;
use PDOException;
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

    public function testRootContentSlugsAreUniqueInSqlite(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required for SQLite migration verification.');
        }

        $databasePath = $this->projectRoot().'/var/test/test.db';

        self::assertFileExists($databasePath);

        $pdo = new PDO('sqlite:'.$databasePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->insertContentProbe($pdo, '99999999-0000-0000-0000-000000000001', 'root-uniqueness-probe');

        $parentUid = $pdo
            ->query("SELECT parent_uid FROM content_item WHERE uid = '99999999-0000-0000-0000-000000000001'")
            ->fetchColumn();

        self::assertSame('/', $parentUid);

        try {
            $this->insertContentProbe($pdo, '99999999-0000-0000-0000-000000000002', 'root-uniqueness-probe');
            self::fail('Duplicate root content slugs must be rejected.');
        } catch (PDOException $exception) {
            self::assertStringContainsString('UNIQUE', strtoupper($exception->getMessage()));
        }
    }

    private function insertContentProbe(PDO $pdo, string $uid, string $slug): void
    {
        $statement = $pdo->prepare(<<<'SQL'
            INSERT INTO content_item (
                uid,
                slug,
                status,
                sort_order,
                custom_url,
                redirect_target,
                schema_uid,
                schema_version,
                active_revision_uid,
                version,
                available_languages,
                available_variants,
                visibility,
                acl_restrictions,
                view_min_level,
                view_group_identifiers,
                edit_min_level,
                edit_group_identifiers,
                manage_min_level,
                manage_group_identifiers,
                metadata
            ) VALUES (
                :uid,
                :slug,
                'draft',
                0,
                NULL,
                NULL,
                NULL,
                NULL,
                NULL,
                1,
                '["en"]',
                '["default"]',
                'public',
                '[]',
                NULL,
                NULL,
                NULL,
                NULL,
                NULL,
                NULL,
                '{}'
            )
            SQL);

        $statement->execute([
            'uid' => $uid,
            'slug' => $slug,
        ]);
    }
}
