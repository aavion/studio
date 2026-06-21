<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Extension\Database\ExtensionDatabaseColumn;
use App\Core\Extension\Database\ExtensionDatabaseIndex;
use App\Core\Extension\Database\ExtensionDatabaseSchemaSynchronizer;
use App\Core\Extension\Database\ExtensionDatabaseTable;
use App\Core\Extension\ExtensionScope;
use App\Core\Extension\ExtensionStatus;
use App\Entity\Extension;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ExtensionDatabaseSchemaUpdateTest extends KernelTestCase
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

    public function testItAppliesOnlyAdditiveUpdatesToExistingExtensionTables(): void
    {
        $synchronizer = new ExtensionDatabaseSchemaSynchronizer($this->connection);
        self::assertTrue($synchronizer->apply($this->extension(), [
            ExtensionDatabaseTable::create('entry', [
                ExtensionDatabaseColumn::string('uid', 36),
                ExtensionDatabaseColumn::string('label', 120),
            ], ['uid']),
        ])->isSuccess());

        $result = $synchronizer->apply($this->extension(), [
            ExtensionDatabaseTable::create('entry', [
                ExtensionDatabaseColumn::string('uid', 36),
                ExtensionDatabaseColumn::string('label', 120),
                ExtensionDatabaseColumn::string('summary', 255, false),
            ], ['uid'], [
                ExtensionDatabaseIndex::index('summary', ['summary']),
            ]),
        ]);

        self::assertTrue($result->isSuccess(), json_encode($result->toArray(), JSON_THROW_ON_ERROR));
        self::assertSame(['ext11_demo_module_entry'], $result->value()['updated']);

        $table = $this->connection->createSchemaManager()->introspectTable('ext11_demo_module_entry');
        self::assertTrue($table->hasColumn('summary'));
        self::assertTrue($table->hasIndex('ext11_demo_module_entry_summary'));
    }

    public function testItRejectsRequiredAdditiveColumnsWithoutDefaults(): void
    {
        $synchronizer = new ExtensionDatabaseSchemaSynchronizer($this->connection);
        self::assertTrue($synchronizer->apply($this->extension(), [
            ExtensionDatabaseTable::create('entry', [
                ExtensionDatabaseColumn::string('uid', 36),
            ], ['uid']),
        ])->isSuccess());

        $result = $synchronizer->apply($this->extension(), [
            ExtensionDatabaseTable::create('entry', [
                ExtensionDatabaseColumn::string('uid', 36),
                ExtensionDatabaseColumn::string('label', 120),
            ], ['uid']),
        ]);

        self::assertFalse($result->isSuccess());
        self::assertSame('required_column_without_default', $result->firstIssue()?->parameters()['%reason%'] ?? null);
        self::assertFalse($this->connection->createSchemaManager()->introspectTable('ext11_demo_module_entry')->hasColumn('label'));
    }

    public function testItRejectsDestructiveExistingExtensionTableChanges(): void
    {
        $synchronizer = new ExtensionDatabaseSchemaSynchronizer($this->connection);
        self::assertTrue($synchronizer->apply($this->extension(), [
            ExtensionDatabaseTable::create('entry', [
                ExtensionDatabaseColumn::string('uid', 36),
                ExtensionDatabaseColumn::string('label', 120),
            ], ['uid']),
        ])->isSuccess());

        $result = $synchronizer->apply($this->extension(), [
            ExtensionDatabaseTable::create('entry', [
                ExtensionDatabaseColumn::string('uid', 36),
            ], ['uid']),
        ]);

        self::assertFalse($result->isSuccess());
        self::assertSame('non_additive_column_drop', $result->firstIssue()?->parameters()['%reason%'] ?? null);
        self::assertTrue($this->connection->createSchemaManager()->introspectTable('ext11_demo_module_entry')->hasColumn('label'));
    }

    public function testItCreatesNewTablesForExtensionSchemaReplacementsWithoutTouchingOldTables(): void
    {
        $synchronizer = new ExtensionDatabaseSchemaSynchronizer($this->connection);
        self::assertTrue($synchronizer->apply($this->extension(), [
            ExtensionDatabaseTable::create('entry_v1', [
                ExtensionDatabaseColumn::string('uid', 36),
                ExtensionDatabaseColumn::string('label', 120),
            ], ['uid']),
        ])->isSuccess());

        $result = $synchronizer->apply($this->extension(), [
            ExtensionDatabaseTable::create('entry_v1', [
                ExtensionDatabaseColumn::string('uid', 36),
                ExtensionDatabaseColumn::string('label', 120),
            ], ['uid']),
            ExtensionDatabaseTable::create('entry_v2', [
                ExtensionDatabaseColumn::string('uid', 36),
                ExtensionDatabaseColumn::text('body', false),
            ], ['uid']),
        ]);

        self::assertTrue($result->isSuccess(), json_encode($result->toArray(), JSON_THROW_ON_ERROR));
        self::assertSame(['ext11_demo_module_entry_v2'], $result->value()['created']);
        self::assertContains('ext11_demo_module_entry_v1', $this->connection->createSchemaManager()->listTableNames());
        self::assertContains('ext11_demo_module_entry_v2', $this->connection->createSchemaManager()->listTableNames());
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
