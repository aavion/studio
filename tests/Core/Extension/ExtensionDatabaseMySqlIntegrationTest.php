<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Extension\Database\ExtensionDatabaseColumn;
use App\Core\Extension\Database\ExtensionDatabaseSchemaSynchronizer;
use App\Core\Extension\Database\ExtensionDatabaseTable;
use App\Core\Extension\ExtensionScope;
use App\Core\Extension\ExtensionStatus;
use App\Entity\Extension;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Throwable;

#[Group('mysql-integration')]
final class ExtensionDatabaseMySqlIntegrationTest extends TestCase
{
    public function testItCleansCreatedTablesAfterLaterDdlFailure(): void
    {
        $connection = $this->connection();
        $this->clearDatabaseOrSkip($connection);

        try {
            $result = (new ExtensionDatabaseSchemaSynchronizer($connection))->apply($this->extension(), [
                ExtensionDatabaseTable::create('entry', [
                    ExtensionDatabaseColumn::string('uid', 36),
                ], ['uid']),
                ExtensionDatabaseTable::create('this_table_name_is_far_too_long_for_mysql_identifier_limit_and_must_fail', [
                    ExtensionDatabaseColumn::string('uid', 36),
                ], ['uid']),
            ]);

            self::assertFalse($result->isSuccess());
            self::assertSame('extension.database.contribution_invalid', $result->firstIssue()?->code());
            self::assertSame([], $connection->createSchemaManager()->listTableNames());
        } finally {
            $this->clearDatabase($connection);
            $connection->close();
        }
    }

    private function connection(): Connection
    {
        if (!extension_loaded('pdo_mysql')) {
            self::markTestSkipped('pdo_mysql is required for optional MySQL/MariaDB integration tests.');
        }

        try {
            $connection = DriverManager::getConnection($this->connectionParams());
            $connection->executeQuery('SELECT 1');
        } catch (Throwable $error) {
            self::markTestSkipped('Optional MySQL/MariaDB test database is not available: '.$error->getMessage());

            throw $error;
        }

        $this->assertUsableIntegrationDatabase($connection);

        return $connection;
    }

    /**
     * @return array<string, mixed>
     */
    private function connectionParams(): array
    {
        $dsn = $_SERVER['MYSQL_TEST_DSN'] ?? $_ENV['MYSQL_TEST_DSN'] ?? getenv('MYSQL_TEST_DSN') ?: null;
        if (is_string($dsn) && '' !== trim($dsn)) {
            return (new DsnParser(['mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql']))->parse($dsn);
        }

        return [
            'driver' => 'pdo_mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'dbname' => 'studio_test',
            'user' => 'test',
            'password' => 'test',
            'charset' => 'utf8mb4',
        ];
    }

    private function assertUsableIntegrationDatabase(Connection $connection): void
    {
        $probeTable = 'studio_mysql_integration_probe';

        try {
            $connection->createSchemaManager()->listTableNames();
            $connection->executeStatement('DROP TABLE IF EXISTS '.$probeTable);
            $connection->executeStatement('CREATE TABLE '.$probeTable.' (id INT NOT NULL PRIMARY KEY)');
            $connection->executeStatement('DROP TABLE '.$probeTable);
        } catch (Throwable $error) {
            self::markTestSkipped(
                'Optional MySQL/MariaDB test database is not readable/writable: '.$error->getMessage(),
            );

            throw $error;
        }
    }

    private function clearDatabaseOrSkip(Connection $connection): void
    {
        try {
            $this->clearDatabase($connection);
        } catch (Throwable $error) {
            self::markTestSkipped(
                'Optional MySQL/MariaDB test database cannot be cleaned safely: '.$error->getMessage(),
            );

            throw $error;
        }
    }

    private function clearDatabase(Connection $connection): void
    {
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($connection->createSchemaManager()->listTableNames() as $tableName) {
                foreach ((array) $connection->getDatabasePlatform()->getDropTableSQL($tableName) as $sql) {
                    $connection->executeStatement($sql);
                }
            }
        } finally {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    private function extension(): Extension
    {
        return new Extension(
            '10000000-0000-7000-8000-000000000705',
            [ExtensionScope::Database],
            'demo-module',
            'extensions/demo-module',
            ExtensionStatus::Active,
        );
    }
}
