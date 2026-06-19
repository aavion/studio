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

final class ExtensionDatabaseSchemaSynchronizerTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->dropTableIfExists('demo_module_entry');
    }

    protected function tearDown(): void
    {
        $this->dropTableIfExists('demo_module_entry');
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
