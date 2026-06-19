<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Extension\Database\ExtensionDatabaseColumn;
use App\Core\Extension\Database\ExtensionDatabaseForeignKey;
use App\Core\Extension\Database\ExtensionDatabaseIndex;
use App\Core\Extension\Database\ExtensionDatabaseSchemaSynchronizer;
use App\Core\Extension\Database\ExtensionDatabaseTable;
use App\Core\Extension\ExtensionScope;
use App\Core\Extension\ExtensionStatus;
use App\Entity\Extension;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ExtensionDatabaseSchemaSynchronizerTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->dropTestTables();
    }

    protected function tearDown(): void
    {
        $this->dropTestTables();
        parent::tearDown();
    }

    public function testItCreatesOnlyExtensionPrefixedTables(): void
    {
        $result = (new ExtensionDatabaseSchemaSynchronizer($this->connection))->apply($this->extension(), [
            ExtensionDatabaseTable::create('entry', [
                ExtensionDatabaseColumn::string('uid', 36),
                ExtensionDatabaseColumn::string('label', 120),
                ExtensionDatabaseColumn::json('payload'),
            ], ['uid'], [
                ExtensionDatabaseIndex::index('label', ['label']),
            ]),
        ]);

        self::assertTrue($result->isSuccess());
        self::assertContains('demo_module_entry', $this->connection->createSchemaManager()->listTableNames());
        self::assertSame(['demo_module_entry'], $result->value()['created']);
    }

    public function testItCreatesExtensionTablesWithForeignKeysAfterReferencedTables(): void
    {
        $result = (new ExtensionDatabaseSchemaSynchronizer($this->connection))->apply($this->extension(), [
            ExtensionDatabaseTable::create('post', [
                ExtensionDatabaseColumn::string('uid', 36),
                ExtensionDatabaseColumn::string('author_uid', 36),
                ExtensionDatabaseColumn::string('title', 120),
            ], ['uid'], [
                ExtensionDatabaseIndex::index('author', ['author_uid']),
            ], [
                ExtensionDatabaseForeignKey::extensionTable('author', ['author_uid'], 'author', ['uid'], [
                    'onDelete' => 'CASCADE',
                ]),
            ]),
            ExtensionDatabaseTable::create('author', [
                ExtensionDatabaseColumn::string('uid', 36),
                ExtensionDatabaseColumn::string('name', 120),
            ], ['uid']),
        ]);

        self::assertTrue($result->isSuccess());
        self::assertSame(['demo_module_author', 'demo_module_post'], $result->value()['created']);

        $post = $this->connection->createSchemaManager()->introspectTable('demo_module_post');
        $foreignKeys = $post->getForeignKeys();

        self::assertCount(1, $foreignKeys);
        self::assertSame('demo_module_author', array_values($foreignKeys)[0]->getForeignTableName());
    }

    public function testItRejectsForeignKeysToMissingExtensionTables(): void
    {
        $result = (new ExtensionDatabaseSchemaSynchronizer($this->connection))->apply($this->extension(), [
            ExtensionDatabaseTable::create('post', [
                ExtensionDatabaseColumn::string('uid', 36),
                ExtensionDatabaseColumn::string('author_uid', 36),
            ], ['uid'], [], [
                ExtensionDatabaseForeignKey::extensionTable('author', ['author_uid'], 'author', ['uid']),
            ]),
        ]);

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.database.contribution_invalid', $result->firstIssue()?->code());
        self::assertNotContains('demo_module_post', $this->connection->createSchemaManager()->listTableNames());
    }

    public function testItRejectsForeignKeysToNonUniqueColumns(): void
    {
        $result = (new ExtensionDatabaseSchemaSynchronizer($this->connection))->apply($this->extension(), [
            ExtensionDatabaseTable::create('author', [
                ExtensionDatabaseColumn::string('uid', 36),
                ExtensionDatabaseColumn::string('handle', 120),
            ], ['uid']),
            ExtensionDatabaseTable::create('post', [
                ExtensionDatabaseColumn::string('uid', 36),
                ExtensionDatabaseColumn::string('author_handle', 120),
            ], ['uid'], [], [
                ExtensionDatabaseForeignKey::extensionTable('author', ['author_handle'], 'author', ['handle']),
            ]),
        ]);

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.database.contribution_invalid', $result->firstIssue()?->code());
        self::assertNotContains('demo_module_author', $this->connection->createSchemaManager()->listTableNames());
        self::assertNotContains('demo_module_post', $this->connection->createSchemaManager()->listTableNames());
    }

    public function testItDropsOnlyTablesOwnedByTheExtensionOnPurge(): void
    {
        $synchronizer = new ExtensionDatabaseSchemaSynchronizer($this->connection);
        self::assertTrue($synchronizer->apply($this->extension(), [
            ExtensionDatabaseTable::create('entry', [
                ExtensionDatabaseColumn::string('uid', 36),
            ], ['uid']),
        ])->isSuccess());

        $result = $synchronizer->purge($this->extension());

        self::assertTrue($result->isSuccess());
        self::assertSame(['demo_module_entry'], $result->value()['dropped']);
        self::assertNotContains('demo_module_entry', $this->connection->createSchemaManager()->listTableNames());
    }

    public function testItDropsExtensionTablesWithForeignKeysInDependencyOrder(): void
    {
        $synchronizer = new ExtensionDatabaseSchemaSynchronizer($this->connection);
        self::assertTrue($synchronizer->apply($this->extension(), [
            ExtensionDatabaseTable::create('author', [
                ExtensionDatabaseColumn::string('uid', 36),
            ], ['uid']),
            ExtensionDatabaseTable::create('post', [
                ExtensionDatabaseColumn::string('uid', 36),
                ExtensionDatabaseColumn::string('author_uid', 36),
            ], ['uid'], [], [
                ExtensionDatabaseForeignKey::extensionTable('author', ['author_uid'], 'author', ['uid']),
            ]),
        ])->isSuccess());

        $result = $synchronizer->purge($this->extension());

        self::assertTrue($result->isSuccess());
        self::assertSame(['demo_module_post', 'demo_module_author'], $result->value()['dropped']);
        self::assertNotContains('demo_module_post', $this->connection->createSchemaManager()->listTableNames());
        self::assertNotContains('demo_module_author', $this->connection->createSchemaManager()->listTableNames());
    }

    private function extension(): Extension
    {
        return new Extension(
            '10000000-0000-7000-8000-000000000702',
            [ExtensionScope::Database],
            'demo-module',
            'extensions/demo-module',
            ExtensionStatus::Active,
        );
    }

    private function dropTestTables(): void
    {
        foreach (['demo_module_post', 'demo_module_author', 'demo_module_entry'] as $tableName) {
            $this->dropTableIfExists($tableName);
        }
    }

    private function dropTableIfExists(string $tableName): void
    {
        if (!in_array($tableName, $this->connection->createSchemaManager()->listTableNames(), true)) {
            return;
        }

        foreach ((array) $this->connection->getDatabasePlatform()->getDropTableSQL($tableName) as $sql) {
            $this->connection->executeStatement($sql);
        }
    }
}
