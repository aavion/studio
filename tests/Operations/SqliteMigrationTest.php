<?php

declare(strict_types=1);

namespace App\Tests\Operations;

use App\Database\TablePrefix;
use App\Tests\Support\FilesystemTestHelper;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20260531000000;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

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
        self::assertContains('ui_alert_inbox', $tables);
        self::assertContains('config_entry', $tables);
        self::assertContains('state_marker', $tables);
        self::assertContains('message_log_entry', $tables);
        self::assertContains('audit_log_entry', $tables);
        self::assertContains('access_log_entry', $tables);
        self::assertContains('security_signal_event', $tables);
        self::assertContains('access_statistic_event', $tables);
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

        $this->insertContentProbe($pdo, '99999999-0000-7000-8000-000000000001', 'root-uniqueness-probe');

        $parentUid = $pdo
            ->query("SELECT parent_uid FROM content_item WHERE uid = '99999999-0000-7000-8000-000000000001'")
            ->fetchColumn();

        self::assertSame('/', $parentUid);

        try {
            $this->insertContentProbe($pdo, '99999999-0000-7000-8000-000000000002', 'root-uniqueness-probe');
            self::fail('Duplicate root content slugs must be rejected.');
        } catch (PDOException $exception) {
            self::assertStringContainsString('UNIQUE', strtoupper($exception->getMessage()));
        }
    }

    public function testPrefixedMigrationsUsePrefixedSchemaObjectNames(): void
    {
        require_once dirname(__DIR__, 2).'/migrations/Version20260531000000.php';

        $previousServerPrefix = $_SERVER['APP_DATABASE_PREFIX'] ?? null;
        $previousEnvPrefix = $_ENV['APP_DATABASE_PREFIX'] ?? null;
        $_SERVER['APP_DATABASE_PREFIX'] = $_ENV['APP_DATABASE_PREFIX'] = 'studio_';

        try {
            $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
            $schema = new Schema();
            $migration = new Version20260531000000($connection, new NullLogger());

            $migration->up($schema);

            $userIndexes = array_map(
                static fn ($index): string => $index->getName(),
                $schema->getTable('user_account')->getIndexes(),
            );
            $alertIndexes = array_map(
                static fn ($index): string => $index->getName(),
                $schema->getTable('ui_alert_inbox')->getIndexes(),
            );
            $signalIndexes = array_map(
                static fn ($index): string => $index->getName(),
                $schema->getTable('security_signal_event')->getIndexes(),
            );
            $userGroupForeignKeys = array_map(
                static fn ($foreignKey): string => $foreignKey->getName(),
                $schema->getTable('user_acl_group')->getForeignKeys(),
            );

            self::assertContains('studio_uniq_user_account_username', $userIndexes);
            self::assertContains('studio_uniq_user_account_email', $userIndexes);
            self::assertContains('studio_pk_user_account', $userIndexes);
            self::assertContains('studio_pk_ui_alert_inbox', $alertIndexes);
            self::assertContains('studio_idx_ui_alert_inbox_topic_cursor', $alertIndexes);
            self::assertContains('studio_idx_ui_alert_inbox_expires_at', $alertIndexes);
            self::assertContains('studio_pk_security_signal_event', $signalIndexes);
            self::assertContains('studio_idx_security_signal_subject_at', $signalIndexes);
            self::assertContains('studio_fk_user_acl_group_user', $userGroupForeignKeys);
            self::assertContains('studio_fk_user_acl_group_group', $userGroupForeignKeys);
        } finally {
            if (null === $previousServerPrefix) {
                unset($_SERVER['APP_DATABASE_PREFIX']);
            } else {
                $_SERVER['APP_DATABASE_PREFIX'] = $previousServerPrefix;
            }

            if (null === $previousEnvPrefix) {
                unset($_ENV['APP_DATABASE_PREFIX']);
            } else {
                $_ENV['APP_DATABASE_PREFIX'] = $previousEnvPrefix;
            }
        }
    }

    public function testPrefixedMigrationsUsePrefixedNamesWhenReverting(): void
    {
        require_once dirname(__DIR__, 2).'/migrations/Version20260531000000.php';

        $previousServerPrefix = $_SERVER['APP_DATABASE_PREFIX'] ?? null;
        $previousEnvPrefix = $_ENV['APP_DATABASE_PREFIX'] ?? null;
        $_SERVER['APP_DATABASE_PREFIX'] = $_ENV['APP_DATABASE_PREFIX'] = 'studio_';

        try {
            $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
            $schema = new Schema();
            $migration = new Version20260531000000($connection, new NullLogger());

            foreach ($this->initialMigrationTables() as $tableName) {
                $table = $schema->createTable('studio_'.$tableName);
                $table->addColumn('uid', 'string', ['length' => 36]);

                if ('content_item' === $tableName) {
                    $table->addColumn('active_revision_uid', 'string', ['length' => 36, 'notnull' => false]);
                }

                if ('content_schema' === $tableName) {
                    $table->addColumn('active_version_uid', 'string', ['length' => 36, 'notnull' => false]);
                }
            }

            $schema->getTable('studio_content_item')->addForeignKeyConstraint(
                'studio_content_revision',
                ['active_revision_uid'],
                ['uid'],
                ['onDelete' => 'SET NULL'],
                'studio_fk_content_item_active_revision',
            );
            $schema->getTable('studio_content_schema')->addForeignKeyConstraint(
                'studio_content_schema_version',
                ['active_version_uid'],
                ['uid'],
                ['onDelete' => 'SET NULL'],
                'studio_fk_content_schema_active_version',
            );

            $migration->down($schema);

            self::assertSame([], $schema->getTables());
        } finally {
            if (null === $previousServerPrefix) {
                unset($_SERVER['APP_DATABASE_PREFIX']);
            } else {
                $_SERVER['APP_DATABASE_PREFIX'] = $previousServerPrefix;
            }

            if (null === $previousEnvPrefix) {
                unset($_ENV['APP_DATABASE_PREFIX']);
            } else {
                $_ENV['APP_DATABASE_PREFIX'] = $previousEnvPrefix;
            }
        }
    }

    /**
     * @return list<string>
     */
    private function initialMigrationTables(): array
    {
        return [
            'messenger_messages',
            'ui_alert_inbox',
            'config_entry',
            'package_setting_entry',
            'scheduler_task',
            'scheduler_task_run',
            'message_log_entry',
            'audit_log_entry',
            'access_log_entry',
            'security_signal_event',
            'state_marker',
            'access_statistic_event',
            'acl_group',
            'user_account',
            'user_acl_group',
            'account_token',
            'api_key',
            'extension_package',
            'site_menu',
            'site_menu_item',
            'content_schema',
            'content_schema_version',
            'content_item',
            'content_revision',
            'content_field_value',
        ];
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
