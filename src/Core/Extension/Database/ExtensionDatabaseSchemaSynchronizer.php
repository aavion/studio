<?php

declare(strict_types=1);

namespace App\Core\Extension\Database;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;
use App\Core\Workflow\WorkflowResult;
use App\Entity\Extension;
use Doctrine\DBAL\Connection;
use Throwable;

final class ExtensionDatabaseSchemaSynchronizer
{
    private readonly ExtensionDatabaseTableNameResolver $names;

    private readonly ExtensionDatabaseReferenceValidator $referenceValidator;

    private readonly ExtensionDatabaseTableOrderer $tableOrderer;

    private readonly ExtensionDatabaseTableBuilder $tableBuilder;

    private readonly ExtensionDatabaseTableUpdater $tableUpdater;

    public function __construct(
        private readonly Connection $connection,
        ?ExtensionDatabaseTableNameResolver $names = null,
        ?ExtensionDatabaseReferenceValidator $referenceValidator = null,
        ?ExtensionDatabaseTableOrderer $tableOrderer = null,
        ?ExtensionDatabaseTableBuilder $tableBuilder = null,
        ?ExtensionDatabaseTableUpdater $tableUpdater = null,
    ) {
        $this->names = $names ?? new ExtensionDatabaseTableNameResolver($connection);
        $this->referenceValidator = $referenceValidator ?? new ExtensionDatabaseReferenceValidator($connection, $this->names);
        $this->tableOrderer = $tableOrderer ?? new ExtensionDatabaseTableOrderer($connection, $this->names);
        $this->tableBuilder = $tableBuilder ?? new ExtensionDatabaseTableBuilder($this->names);
        $this->tableUpdater = $tableUpdater ?? new ExtensionDatabaseTableUpdater($connection, $this->tableBuilder);
    }

    /**
     * @param iterable<ExtensionDatabaseTable> $tables
     *
     * @return WorkflowResult<array{created: list<string>, existing: list<string>, updated: list<string>}>
     */
    public function apply(Extension $extension, iterable $tables): WorkflowResult
    {
        $created = [];
        $existing = [];
        $updated = [];
        $schemaManager = $this->connection->createSchemaManager();
        $knownTables = array_map('strtolower', $schemaManager->listTableNames());
        $pendingTables = [];
        $existingTables = [];

        foreach ($tables as $table) {
            if (!$table instanceof ExtensionDatabaseTable) {
                return $this->invalid($extension, 'table_invalid');
            }

            $physicalName = $this->names->tableName($extension, $table);
            if (!$this->names->isOwnedTableName($extension, $physicalName)) {
                return $this->invalid($extension, 'table_name_not_owned', ['table' => $physicalName]);
            }

            if (!$this->names->isPortableIdentifier($physicalName)) {
                return $this->invalid($extension, 'table_name_too_long', [
                    'table' => $physicalName,
                    'max_length' => ExtensionDatabaseTableNameResolver::MAX_IDENTIFIER_LENGTH,
                ]);
            }

            if (in_array(strtolower($physicalName), $knownTables, true)) {
                $existing[] = $physicalName;
                $existingTables[$physicalName] = $table;
                continue;
            }

            $pendingTables[$physicalName] = $table;
        }

        $validation = $this->referenceValidator->validatePendingTables($extension, [...$pendingTables, ...$existingTables], $knownTables);
        if (!$validation->isSuccess()) {
            return $validation;
        }

        foreach ($existingTables as $physicalName => $table) {
            $sync = $this->tableUpdater->syncExistingTable($extension, $physicalName, $table);
            if (!$sync->isSuccess()) {
                return $sync;
            }

            if (($sync->context()['updated'] ?? false) === true) {
                $updated[] = $physicalName;
            }
        }

        $ordering = $this->tableOrderer->pendingTablesForCreation($extension, $pendingTables);
        if ($ordering instanceof WorkflowResult) {
            return $ordering;
        }

        foreach ($ordering as $physicalName) {
            $table = $pendingTables[$physicalName];

            try {
                $this->createTable($extension, $physicalName, $table);
            } catch (Throwable $error) {
                $cleanup = $this->dropTables($extension, [...$created, $physicalName]);

                return WorkflowResult::failed([
                    Message::create(
                        ExtensionMessageCode::EXTENSION_DATABASE_CONTRIBUTION_INVALID,
                        ExtensionMessageKey::EXTENSION_DATABASE_CONTRIBUTION_INVALID,
                        ['%reason%' => 'create_table_failed'],
                        ['extension' => $extension->extensionName(), 'table' => $physicalName, 'exception' => $error::class, 'message' => $error->getMessage()],
                        MessageLevel::Exception,
                    ),
                    ...$cleanup->issues(),
                ], [
                    'extension' => $extension->extensionName(),
                    'table' => $physicalName,
                    'created_before_failure' => $created,
                    'cleanup_attempted' => [...$created, $physicalName],
                    'cleanup_context' => $cleanup->context(),
                ], $cleanup->messages());
            }

            $created[] = $physicalName;
            $knownTables[] = strtolower($physicalName);
        }

        return WorkflowResult::success([
            'created' => $created,
            'existing' => $existing,
            'updated' => $updated,
        ], [
            'extension' => $extension->extensionName(),
            'created' => $created,
            'existing' => $existing,
            'updated' => $updated,
        ], [
            Message::debug(
                ExtensionMessageCode::EXTENSION_DATABASE_SYNC_COMPLETED,
                ExtensionMessageKey::EXTENSION_DATABASE_SYNC_COMPLETED,
                ['%extension%' => $extension->extensionName(), '%count%' => count($created)],
                ['extension' => $extension->extensionName(), 'created' => $created, 'existing' => $existing, 'updated' => $updated],
            ),
        ]);
    }

    /**
     * @return WorkflowResult<array{dropped: list<string>}>
     */
    public function purge(Extension $extension): WorkflowResult
    {
        $schemaManager = $this->connection->createSchemaManager();

        $ownedTables = [];

        foreach ($schemaManager->listTableNames() as $tableName) {
            if (!$this->names->isOwnedTableName($extension, $tableName)) {
                continue;
            }

            $ownedTables[] = $tableName;
        }

        $drop = $this->dropTables($extension, $ownedTables);
        if (!$drop->isSuccess()) {
            return $drop;
        }

        $dropped = $drop->value()['dropped'];

        return WorkflowResult::success([
            'dropped' => $dropped,
        ], [
            'extension' => $extension->extensionName(),
            'dropped' => $dropped,
        ], [
            Message::debug(
                ExtensionMessageCode::EXTENSION_DATABASE_PURGE_COMPLETED,
                ExtensionMessageKey::EXTENSION_DATABASE_PURGE_COMPLETED,
                ['%extension%' => $extension->extensionName(), '%count%' => count($dropped)],
                ['extension' => $extension->extensionName(), 'dropped' => $dropped],
            ),
        ]);
    }

    /**
     * @param list<string> $tableNames
     *
     * @return WorkflowResult<array{dropped: list<string>}>
     */
    public function dropTables(Extension $extension, array $tableNames): WorkflowResult
    {
        $schemaManager = $this->connection->createSchemaManager();
        $knownTables = array_fill_keys(array_map('strtolower', $schemaManager->listTableNames()), true);
        $existingOwnedTables = [];

        foreach (array_values(array_unique($tableNames)) as $tableName) {
            if (!$this->names->isOwnedTableName($extension, $tableName)) {
                return $this->invalid($extension, 'table_name_not_owned', ['table' => $tableName]);
            }

            if (isset($knownTables[strtolower($tableName)])) {
                $existingOwnedTables[] = $tableName;
            }
        }

        $dropped = [];
        $platform = $this->connection->getDatabasePlatform();

        foreach ($this->tableOrderer->existingTablesForDrop($existingOwnedTables) as $tableName) {
            try {
                foreach ((array) $platform->getDropTableSQL($tableName) as $sql) {
                    $this->connection->executeStatement($sql);
                }
            } catch (Throwable $error) {
                return WorkflowResult::failed([
                    Message::create(
                        ExtensionMessageCode::EXTENSION_DATABASE_CONTRIBUTION_INVALID,
                        ExtensionMessageKey::EXTENSION_DATABASE_CONTRIBUTION_INVALID,
                        ['%reason%' => 'drop_table_failed'],
                        ['extension' => $extension->extensionName(), 'table' => $tableName, 'exception' => $error::class, 'message' => $error->getMessage()],
                        MessageLevel::Exception,
                    ),
                ], [
                    'extension' => $extension->extensionName(),
                    'table' => $tableName,
                    'dropped_before_failure' => $dropped,
                ]);
            }

            $dropped[] = $tableName;
        }

        return WorkflowResult::success(['dropped' => $dropped], [
            'extension' => $extension->extensionName(),
            'dropped' => $dropped,
        ]);
    }

    private function createTable(Extension $extension, string $physicalName, ExtensionDatabaseTable $definition): void
    {
        $table = $this->tableBuilder->table($extension, $physicalName, $definition);

        foreach ($this->connection->getDatabasePlatform()->getCreateTableSQL($table) as $sql) {
            $this->connection->executeStatement($sql);
        }
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return WorkflowResult<null>
     */
    private function invalid(Extension $extension, string $reason, array $context = []): WorkflowResult
    {
        return WorkflowResult::invalid([
            Message::create(
                ExtensionMessageCode::EXTENSION_DATABASE_CONTRIBUTION_INVALID,
                ExtensionMessageKey::EXTENSION_DATABASE_CONTRIBUTION_INVALID,
                ['%reason%' => $reason],
                ['extension' => $extension->extensionName(), ...$context],
                MessageLevel::Error,
            ),
        ]);
    }
}
