<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Extension\Database\ExtensionDatabaseTableNameResolver;
use App\Core\Extension\ExtensionDatabaseFacade;
use App\Core\Extension\ExtensionRuntime;
use App\Core\Extension\ExtensionRuntimeServices;
use App\Tests\Support\FilesystemTestHelper;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class ExtensionRuntimeDatabaseTest extends TestCase
{
    use FilesystemTestHelper;

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = dirname(__DIR__, 3);
        $this->removeDirectory($this->projectDir.'/extensions/db-facade');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir.'/extensions/db-facade');
        ExtensionRuntime::reset();
    }

    public function testItReadsAndWritesCallingExtensionTables(): void
    {
        $connection = $this->connection();
        $connection->executeStatement('CREATE TABLE ext9_db_facade_entry (uid VARCHAR(36) NOT NULL PRIMARY KEY, label VARCHAR(120) NOT NULL, payload CLOB DEFAULT NULL, enabled BOOLEAN DEFAULT NULL)');
        ExtensionRuntime::configure(new ExtensionRuntimeServices(
            $this->projectDir,
            databases: new ExtensionDatabaseFacade($connection, new ExtensionDatabaseTableNameResolver($connection)),
        ));
        $this->writeExtensionFile(<<<'PHP'
            <?php

            $inserted = extension_db_insert('entry', [
                'uid' => 'entry-one',
                'label' => 'First',
                'payload' => ['ok' => true],
                'enabled' => false,
            ]);
            $before = extension_db_fetch('entry', ['uid' => 'entry-one'], ['limit' => 10]);
            $updated = extension_db_update('entry', ['uid' => 'entry-one'], ['label' => 'Updated']);
            $after = extension_db_fetch('entry', ['uid' => 'entry-one']);
            $deleted = extension_db_delete('entry', ['uid' => 'entry-one']);
            $final = extension_db_fetch('entry');

            return [$inserted, $before, $updated, $after, $deleted, $final];
            PHP);

        [$inserted, $before, $updated, $after, $deleted, $final] = require $this->projectDir.'/extensions/db-facade/extension.php';

        self::assertTrue($inserted);
        self::assertSame('First', $before[0]['label']);
        self::assertSame('{"ok":true}', $before[0]['payload']);
        self::assertSame(1, $updated);
        self::assertSame('Updated', $after[0]['label']);
        self::assertSame(1, $deleted);
        self::assertSame([], $final);
    }

    public function testItRejectsInvalidIdentifiersEmptyMutationsAndNonExtensionCallers(): void
    {
        $connection = $this->connection();
        $connection->executeStatement('CREATE TABLE ext9_db_facade_entry (uid VARCHAR(36) NOT NULL PRIMARY KEY, label VARCHAR(120) NOT NULL)');
        ExtensionRuntime::configure(new ExtensionRuntimeServices(
            $this->projectDir,
            databases: new ExtensionDatabaseFacade($connection, new ExtensionDatabaseTableNameResolver($connection)),
        ));
        self::assertSame([], ExtensionRuntime::dbFetch('entry'));
        self::assertFalse(ExtensionRuntime::dbInsert('entry', ['uid' => 'outside']));

        $this->writeExtensionFile(<<<'PHP'
            <?php

            extension_db_insert('entry', ['uid' => 'entry-one', 'label' => 'First']);

            return [
                extension_db_fetch('../entry'),
                extension_db_fetch('entry', ['uid OR 1=1' => 'entry-one']),
                extension_db_insert('entry', ['uid' => 'entry-two', 'label' => str_repeat('x', 1048577)]),
                extension_db_insert('entry', ['uid' => 'entry-three', 'label' => new stdClass()]),
                extension_db_update('entry', [], ['label' => 'Unsafe']),
                extension_db_delete('entry', []),
                extension_db_fetch('entry'),
            ];
            PHP);

        [$invalidTable, $invalidCriteria, $oversized, $objectValue, $emptyUpdate, $emptyDelete, $remaining] = require $this->projectDir.'/extensions/db-facade/extension.php';

        self::assertSame([], $invalidTable);
        self::assertSame([], $invalidCriteria);
        self::assertFalse($oversized);
        self::assertFalse($objectValue);
        self::assertSame(0, $emptyUpdate);
        self::assertSame(0, $emptyDelete);
        self::assertSame([['uid' => 'entry-one', 'label' => 'First']], $remaining);
    }

    private function connection(): Connection
    {
        return DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
            'system_database_prefix' => '',
        ]);
    }

    private function writeExtensionFile(string $contents): void
    {
        $this->writeTestFile($this->projectDir, 'extensions/db-facade/extension.php', $contents);
    }
}
