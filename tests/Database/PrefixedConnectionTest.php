<?php

declare(strict_types=1);

namespace App\Tests\Database;

use App\Database\PrefixedConnection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class PrefixedConnectionTest extends TestCase
{
    private mixed $previousServerPrefix;
    private mixed $previousEnvPrefix;

    protected function setUp(): void
    {
        $this->previousServerPrefix = $_SERVER['APP_DATABASE_PREFIX'] ?? null;
        $this->previousEnvPrefix = $_ENV['APP_DATABASE_PREFIX'] ?? null;
        $_SERVER['APP_DATABASE_PREFIX'] = 'studio_';
        $_ENV['APP_DATABASE_PREFIX'] = 'studio_';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['APP_DATABASE_PREFIX'], $_ENV['APP_DATABASE_PREFIX']);

        if (null !== $this->previousServerPrefix) {
            $_SERVER['APP_DATABASE_PREFIX'] = $this->previousServerPrefix;
        }

        if (null !== $this->previousEnvPrefix) {
            $_ENV['APP_DATABASE_PREFIX'] = $this->previousEnvPrefix;
        }
    }

    public function testItPrefixesKnownTablesForSqlAndTableHelpers(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
            'wrapperClass' => PrefixedConnection::class,
        ]);

        $connection->executeStatement('CREATE TABLE user_account (uid VARCHAR(36) NOT NULL PRIMARY KEY, username VARCHAR(80) NOT NULL)');
        $connection->insert('user_account', ['uid' => '1', 'username' => 'admin']);

        self::assertSame('admin', $connection->fetchOne('SELECT username FROM user_account WHERE uid = ?', ['1']));
        self::assertSame('studio_user_account', $connection->fetchOne("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'studio_user_account'"));
        self::assertFalse($connection->fetchOne("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'user_account'"));
    }

    public function testItPrefixesRawSqlStatementsForKnownTables(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
            'wrapperClass' => PrefixedConnection::class,
        ]);

        $connection->executeStatement('CREATE TABLE user_account (uid VARCHAR(36) NOT NULL PRIMARY KEY, username VARCHAR(80) NOT NULL)');
        $connection->executeStatement('CREATE TABLE acl_group (uid VARCHAR(36) NOT NULL PRIMARY KEY, identifier VARCHAR(80) NOT NULL)');
        $connection->executeStatement('CREATE TABLE user_acl_group (user_uid VARCHAR(36) NOT NULL, group_uid VARCHAR(36) NOT NULL)');
        $connection->executeStatement("INSERT INTO user_account (uid, username) VALUES ('user-1', 'admin')");
        $connection->executeStatement("INSERT INTO acl_group (uid, identifier) VALUES ('group-1', 'administrators')");
        $connection->executeStatement("INSERT INTO user_acl_group (user_uid, group_uid) VALUES ('user-1', 'group-1')");
        $connection->executeStatement("UPDATE user_account SET username = 'owner' WHERE uid = 'user-1'");

        $identifier = $connection->fetchOne(
            'SELECT g.identifier FROM user_account u INNER JOIN acl_group g ON g.uid = ? INNER JOIN user_acl_group ug ON ug.user_uid = u.uid WHERE u.username = ?',
            ['group-1', 'owner'],
        );

        self::assertSame('administrators', $identifier);

        $connection->executeStatement("DELETE FROM user_acl_group WHERE user_uid = 'user-1'");

        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM user_acl_group WHERE user_uid = 'user-1'"));
    }

    public function testItLeavesDoctrineMigrationMetadataUnprefixed(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
            'wrapperClass' => PrefixedConnection::class,
        ]);

        $connection->executeStatement('CREATE TABLE doctrine_migration_versions (version VARCHAR(191) NOT NULL PRIMARY KEY)');

        self::assertSame(
            'doctrine_migration_versions',
            $connection->fetchOne("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'doctrine_migration_versions'"),
        );
        self::assertFalse($connection->fetchOne("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'studio_doctrine_migration_versions'"));
    }
}
