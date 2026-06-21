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
use App\Core\Message\MessageException;
use App\Core\Validation\IdentifierSpec;
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
        self::assertContains('ext11_demo_module_entry', $this->connection->createSchemaManager()->listTableNames());
        self::assertSame(['ext11_demo_module_entry'], $result->value()['created']);
    }

    public function testItUsesBoundedOwnerPrefixesForLongExtensionSlugs(): void
    {
        $extension = $this->extension('demo-module-with-a-very-long-extension-slug-for-portability');

        $result = (new ExtensionDatabaseSchemaSynchronizer($this->connection))->apply($extension, [
            ExtensionDatabaseTable::create('entry', [
                ExtensionDatabaseColumn::string('uid', 36),
            ], ['uid']),
        ]);

        self::assertTrue($result->isSuccess(), json_encode($result->toArray(), JSON_THROW_ON_ERROR));
        self::assertCount(1, $result->value()['created']);
        self::assertLessThanOrEqual(63, strlen($result->value()['created'][0]));
        self::assertContains($result->value()['created'][0], $this->connection->createSchemaManager()->listTableNames());
    }

    public function testItRejectsCombinedExtensionTableNamesThatExceedPortableIdentifierLength(): void
    {
        $result = (new ExtensionDatabaseSchemaSynchronizer($this->connection))->apply($this->extension(), [
            ExtensionDatabaseTable::create(str_repeat('table_name_', 5).'entry', [
                ExtensionDatabaseColumn::string('uid', 36),
            ], ['uid']),
        ]);

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.database.contribution_invalid', $result->firstIssue()?->code());
        self::assertSame('table_name_too_long', $result->firstIssue()?->parameters()['%reason%'] ?? null);
    }

    public function testItRejectsDatabaseColumnIdentifiersThatExceedPortableIdentifierLength(): void
    {
        $this->expectException(MessageException::class);
        $this->expectExceptionMessage('message.extension.database.contribution_invalid');

        ExtensionDatabaseColumn::string(str_repeat('a', IdentifierSpec::MAX_PORTABLE_DATABASE_IDENTIFIER_LENGTH + 1), 36);
    }

    public function testItRejectsUnsupportedDatabaseColumnOptionsBeforeDdl(): void
    {
        $this->expectException(MessageException::class);
        $this->expectExceptionMessage('message.extension.database.contribution_invalid');

        new ExtensionDatabaseColumn('uid', 'string', [
            'length' => 36,
            'columnDefinition' => 'VARCHAR(36) NOT NULL',
        ]);
    }

    public function testItDropsCurrentTableWhenCreateFailsAfterTableStatement(): void
    {
        $this->connection->executeStatement('CREATE TABLE extension_index_collision_holder (label VARCHAR(120) NOT NULL)');
        $this->connection->executeStatement('CREATE INDEX ext11_demo_module_entry_label ON extension_index_collision_holder (label)');

        try {
            $result = (new ExtensionDatabaseSchemaSynchronizer($this->connection))->apply($this->extension(), [
                ExtensionDatabaseTable::create('entry', [
                    ExtensionDatabaseColumn::string('uid', 36),
                    ExtensionDatabaseColumn::string('label', 120),
                ], ['uid'], [
                    ExtensionDatabaseIndex::index('label', ['label']),
                ]),
            ]);
        } finally {
            $this->connection->executeStatement('DROP TABLE IF EXISTS extension_index_collision_holder');
        }

        self::assertFalse($result->isSuccess());
        self::assertSame('extension.database.contribution_invalid', $result->firstIssue()?->code());
        self::assertNotContains('ext11_demo_module_entry', $this->connection->createSchemaManager()->listTableNames());
        self::assertContains('ext11_demo_module_entry', $result->context()['cleanup_attempted']);
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
        self::assertSame(['ext11_demo_module_author', 'ext11_demo_module_post'], $result->value()['created']);

        $post = $this->connection->createSchemaManager()->introspectTable('ext11_demo_module_post');
        $foreignKeys = $post->getForeignKeys();

        self::assertCount(1, $foreignKeys);
        self::assertSame('ext11_demo_module_author', array_values($foreignKeys)[0]->getForeignTableName());
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
        self::assertNotContains('ext11_demo_module_post', $this->connection->createSchemaManager()->listTableNames());
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
        self::assertNotContains('ext11_demo_module_author', $this->connection->createSchemaManager()->listTableNames());
        self::assertNotContains('ext11_demo_module_post', $this->connection->createSchemaManager()->listTableNames());
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
        self::assertSame(['ext11_demo_module_entry'], $result->value()['dropped']);
        self::assertNotContains('ext11_demo_module_entry', $this->connection->createSchemaManager()->listTableNames());
    }

    public function testItDoesNotTreatNormalizedSlugPrefixesAsOwnedTables(): void
    {
        $synchronizer = new ExtensionDatabaseSchemaSynchronizer($this->connection);
        self::assertTrue($synchronizer->apply($this->extension(), [
            ExtensionDatabaseTable::create('entry', [
                ExtensionDatabaseColumn::string('uid', 36),
            ], ['uid']),
        ])->isSuccess());

        $result = $synchronizer->purge(new Extension(
            '10000000-0000-7000-8000-000000000704',
            [ExtensionScope::Database],
            'demo',
            'extensions/demo',
            ExtensionStatus::Active,
        ));

        self::assertTrue($result->isSuccess());
        self::assertSame([], $result->value()['dropped']);
        self::assertContains('ext11_demo_module_entry', $this->connection->createSchemaManager()->listTableNames());
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
        self::assertSame(['ext11_demo_module_post', 'ext11_demo_module_author'], $result->value()['dropped']);
        self::assertNotContains('ext11_demo_module_post', $this->connection->createSchemaManager()->listTableNames());
        self::assertNotContains('ext11_demo_module_author', $this->connection->createSchemaManager()->listTableNames());
    }

    private function extension(string $extensionName = 'demo-module'): Extension
    {
        return new Extension(
            '10000000-0000-7000-8000-000000000702',
            [ExtensionScope::Database],
            $extensionName,
            'extensions/'.$extensionName,
            ExtensionStatus::Active,
        );
    }

    private function dropTestTables(): void
    {
        $this->dropTableIfExists('extension_index_collision_holder');

        foreach ($this->connection->createSchemaManager()->listTableNames() as $tableName) {
            if (1 === preg_match('#^ext\d+_#', $tableName)) {
                $this->dropTableIfExists($tableName);
            }
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
