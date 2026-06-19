<?php

declare(strict_types=1);

namespace App\Core\Extension\Database;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;
use App\Core\Workflow\WorkflowResult;
use App\Database\TablePrefix;
use App\Entity\Extension;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Throwable;

final readonly class ExtensionDatabaseSchemaSynchronizer
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param iterable<ExtensionDatabaseTable> $tables
     *
     * @return WorkflowResult<array{created: list<string>, existing: list<string>}>
     */
    public function apply(Extension $extension, iterable $tables): WorkflowResult
    {
        $created = [];
        $existing = [];
        $schemaManager = $this->connection->createSchemaManager();
        $knownTables = array_map('strtolower', $schemaManager->listTableNames());
        $databasePrefix = $this->databasePrefix();
        $pendingTables = [];

        foreach ($tables as $table) {
            if (!$table instanceof ExtensionDatabaseTable) {
                return $this->invalid($extension, 'table_invalid');
            }

            $physicalName = $table->physicalName($extension->extensionName(), $databasePrefix);
            if (!$this->isOwnedTableName($extension, $physicalName)) {
                return $this->invalid($extension, 'table_name_not_owned', ['table' => $physicalName]);
            }

            if (in_array(strtolower($physicalName), $knownTables, true)) {
                $existing[] = $physicalName;
                continue;
            }

            $pendingTables[$physicalName] = $table;
        }

        $validation = $this->validatePendingTables($extension, $pendingTables, $knownTables, $databasePrefix);
        if (!$validation->isSuccess()) {
            return $validation;
        }

        $ordering = $this->orderedPendingTables($extension, $pendingTables, $databasePrefix);
        if ($ordering instanceof WorkflowResult) {
            return $ordering;
        }

        foreach ($ordering as $physicalName) {
            $table = $pendingTables[$physicalName];

            try {
                $this->createTable($extension, $physicalName, $table, $databasePrefix);
            } catch (Throwable $error) {
                return WorkflowResult::failed([
                    Message::create(
                        ExtensionMessageCode::EXTENSION_DATABASE_CONTRIBUTION_INVALID,
                        ExtensionMessageKey::EXTENSION_DATABASE_CONTRIBUTION_INVALID,
                        ['%reason%' => 'create_table_failed'],
                        ['extension' => $extension->extensionName(), 'table' => $physicalName, 'exception' => $error::class, 'message' => $error->getMessage()],
                        MessageLevel::Exception,
                    ),
                ]);
            }

            $created[] = $physicalName;
            $knownTables[] = strtolower($physicalName);
        }

        return WorkflowResult::success([
            'created' => $created,
            'existing' => $existing,
        ], [
            'extension' => $extension->extensionName(),
            'created' => $created,
            'existing' => $existing,
        ], [
            Message::debug(
                ExtensionMessageCode::EXTENSION_DATABASE_SYNC_COMPLETED,
                ExtensionMessageKey::EXTENSION_DATABASE_SYNC_COMPLETED,
                ['%extension%' => $extension->extensionName(), '%count%' => count($created)],
                ['extension' => $extension->extensionName(), 'created' => $created, 'existing' => $existing],
            ),
        ]);
    }

    /**
     * @return WorkflowResult<array{dropped: list<string>}>
     */
    public function purge(Extension $extension): WorkflowResult
    {
        $dropped = [];
        $platform = $this->connection->getDatabasePlatform();
        $schemaManager = $this->connection->createSchemaManager();

        $ownedTables = [];

        foreach ($schemaManager->listTableNames() as $tableName) {
            if (!$this->isOwnedTableName($extension, $tableName)) {
                continue;
            }

            $ownedTables[] = $tableName;
        }

        foreach ($this->orderedTablesForDrop($ownedTables) as $tableName) {
            foreach ((array) $platform->getDropTableSQL($tableName) as $sql) {
                $this->connection->executeStatement($sql);
            }

            $dropped[] = $tableName;
        }

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

    private function createTable(Extension $extension, string $physicalName, ExtensionDatabaseTable $definition, string $databasePrefix): void
    {
        $table = new Table($physicalName);

        foreach ($definition->columns() as $column) {
            $table->addColumn($column->name(), $column->type(), $column->options());
        }

        if ([] !== $definition->primaryKey()) {
            $table->setPrimaryKey($definition->primaryKey(), $this->shortName('pk_'.$physicalName));
        }

        foreach ($definition->indexes() as $index) {
            $name = $this->shortName($physicalName.'_'.$index->name());
            if ($index->unique()) {
                $table->addUniqueIndex($index->columns(), $name);
                continue;
            }

            $table->addIndex($index->columns(), $name);
        }

        foreach ($definition->foreignKeys() as $foreignKey) {
            $table->addForeignKeyConstraint(
                $this->referencedPhysicalTableName($extension, $foreignKey, $databasePrefix),
                $foreignKey->localColumns(),
                $foreignKey->referencedColumns(),
                $foreignKey->options(),
                $this->shortName($physicalName.'_'.$foreignKey->name()),
            );
        }

        foreach ($this->connection->getDatabasePlatform()->getCreateTableSQL($table) as $sql) {
            $this->connection->executeStatement($sql);
        }
    }

    /**
     * @param array<string, ExtensionDatabaseTable> $pendingTables
     * @param list<string> $knownTables
     *
     * @return WorkflowResult<null>
     */
    private function validatePendingTables(Extension $extension, array $pendingTables, array $knownTables, string $databasePrefix): WorkflowResult
    {
        foreach ($pendingTables as $physicalName => $table) {
            foreach ($table->foreignKeys() as $foreignKey) {
                $referencedPhysicalName = $this->referencedPhysicalTableName($extension, $foreignKey, $databasePrefix);

                if (isset($pendingTables[$referencedPhysicalName])) {
                    $validation = $this->validateDefinitionReference($extension, $foreignKey, $pendingTables[$referencedPhysicalName]);
                    if (!$validation->isSuccess()) {
                        return $validation;
                    }

                    continue;
                }

                if (!in_array(strtolower($referencedPhysicalName), $knownTables, true)) {
                    return $this->invalid($extension, 'foreign_key_reference_missing', [
                        'table' => $physicalName,
                        'foreign_key' => $foreignKey->name(),
                        'referenced_table' => $referencedPhysicalName,
                    ]);
                }

                try {
                    $referencedTable = $this->connection->createSchemaManager()->introspectTable($referencedPhysicalName);
                } catch (Throwable $error) {
                    return $this->invalid($extension, 'foreign_key_reference_unreadable', [
                        'table' => $physicalName,
                        'foreign_key' => $foreignKey->name(),
                        'referenced_table' => $referencedPhysicalName,
                        'exception' => $error::class,
                        'message' => $error->getMessage(),
                    ]);
                }

                $validation = $this->validateDatabaseReference($extension, $foreignKey, $referencedTable);
                if (!$validation->isSuccess()) {
                    return $validation;
                }
            }
        }

        return WorkflowResult::success(null);
    }

    /**
     * @param array<string, ExtensionDatabaseTable> $pendingTables
     *
     * @return list<string>|WorkflowResult<null>
     */
    private function orderedPendingTables(Extension $extension, array $pendingTables, string $databasePrefix): array|WorkflowResult
    {
        $ordered = [];
        $visited = [];
        $visiting = [];

        $visit = function (string $physicalName) use (&$visit, &$ordered, &$visited, &$visiting, $extension, $pendingTables, $databasePrefix): bool {
            if (isset($visited[$physicalName])) {
                return true;
            }

            if (isset($visiting[$physicalName])) {
                return false;
            }

            $visiting[$physicalName] = true;

            foreach ($pendingTables[$physicalName]->foreignKeys() as $foreignKey) {
                $referencedPhysicalName = $this->referencedPhysicalTableName($extension, $foreignKey, $databasePrefix);
                if ($referencedPhysicalName === $physicalName) {
                    continue;
                }

                if (isset($pendingTables[$referencedPhysicalName]) && !$visit($referencedPhysicalName)) {
                    return false;
                }
            }

            unset($visiting[$physicalName]);
            $visited[$physicalName] = true;
            $ordered[] = $physicalName;

            return true;
        };

        foreach (array_keys($pendingTables) as $physicalName) {
            if (!$visit($physicalName)) {
                return $this->invalid($extension, 'foreign_key_reference_cycle', ['table' => $physicalName]);
            }
        }

        return $ordered;
    }

    private function validateDefinitionReference(Extension $extension, ExtensionDatabaseForeignKey $foreignKey, ExtensionDatabaseTable $referencedTable): WorkflowResult
    {
        $availableColumns = [];
        foreach ($referencedTable->columns() as $column) {
            $availableColumns[$column->name()] = true;
        }

        foreach ($foreignKey->referencedColumns() as $column) {
            if (!isset($availableColumns[$column])) {
                return $this->invalid($extension, 'foreign_key_referenced_column_missing', [
                    'foreign_key' => $foreignKey->name(),
                    'referenced_table' => $referencedTable->name(),
                    'referenced_column' => $column,
                ]);
            }
        }

        if (!$this->definitionHasUniqueColumns($referencedTable, $foreignKey->referencedColumns())) {
            return $this->invalid($extension, 'foreign_key_reference_not_unique', [
                'foreign_key' => $foreignKey->name(),
                'referenced_table' => $referencedTable->name(),
                'referenced_columns' => $foreignKey->referencedColumns(),
            ]);
        }

        return WorkflowResult::success(null);
    }

    private function validateDatabaseReference(Extension $extension, ExtensionDatabaseForeignKey $foreignKey, Table $referencedTable): WorkflowResult
    {
        foreach ($foreignKey->referencedColumns() as $column) {
            if (!$referencedTable->hasColumn($column)) {
                return $this->invalid($extension, 'foreign_key_referenced_column_missing', [
                    'foreign_key' => $foreignKey->name(),
                    'referenced_table' => $referencedTable->getName(),
                    'referenced_column' => $column,
                ]);
            }
        }

        if (!$this->databaseTableHasUniqueColumns($referencedTable, $foreignKey->referencedColumns())) {
            return $this->invalid($extension, 'foreign_key_reference_not_unique', [
                'foreign_key' => $foreignKey->name(),
                'referenced_table' => $referencedTable->getName(),
                'referenced_columns' => $foreignKey->referencedColumns(),
            ]);
        }

        return WorkflowResult::success(null);
    }

    /**
     * @param list<string> $columns
     */
    private function definitionHasUniqueColumns(ExtensionDatabaseTable $table, array $columns): bool
    {
        if ($this->sameColumns($table->primaryKey(), $columns)) {
            return true;
        }

        foreach ($table->indexes() as $index) {
            if ($index->unique() && $this->sameColumns($index->columns(), $columns)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $columns
     */
    private function databaseTableHasUniqueColumns(Table $table, array $columns): bool
    {
        foreach ($table->getIndexes() as $index) {
            if (($index->isPrimary() || $index->isUnique()) && $this->sameColumns($index->getColumns(), $columns)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $left
     * @param list<string> $right
     */
    private function sameColumns(array $left, array $right): bool
    {
        return array_map('strtolower', $left) === array_map('strtolower', $right);
    }

    private function referencedPhysicalTableName(Extension $extension, ExtensionDatabaseForeignKey $foreignKey, string $databasePrefix): string
    {
        return $databasePrefix.str_replace('-', '_', $extension->extensionName()).'_'.$foreignKey->referencedTable();
    }

    /**
     * @param list<string> $tableNames
     *
     * @return list<string>
     */
    private function orderedTablesForDrop(array $tableNames): array
    {
        $ownedTables = array_fill_keys($tableNames, true);
        $referencedBy = [];

        foreach ($tableNames as $tableName) {
            $referencedBy[$tableName] = [];
        }

        $schemaManager = $this->connection->createSchemaManager();

        foreach ($tableNames as $tableName) {
            try {
                $table = $schemaManager->introspectTable($tableName);
            } catch (Throwable) {
                continue;
            }

            foreach ($table->getForeignKeys() as $foreignKey) {
                $referencedTable = $foreignKey->getForeignTableName();
                if ($referencedTable === $tableName || !isset($ownedTables[$referencedTable])) {
                    continue;
                }

                $referencedBy[$referencedTable][] = $tableName;
            }
        }

        $ordered = [];
        $visited = [];
        $visiting = [];

        $visit = function (string $tableName) use (&$visit, &$ordered, &$visited, &$visiting, $referencedBy): void {
            if (isset($visited[$tableName])) {
                return;
            }

            if (isset($visiting[$tableName])) {
                return;
            }

            $visiting[$tableName] = true;

            foreach ($referencedBy[$tableName] ?? [] as $referencingTable) {
                $visit($referencingTable);
            }

            unset($visiting[$tableName]);
            $visited[$tableName] = true;
            $ordered[] = $tableName;
        };

        foreach ($tableNames as $tableName) {
            $visit($tableName);
        }

        return $ordered;
    }

    private function isOwnedTableName(Extension $extension, string $tableName): bool
    {
        $ownedPrefix = $this->databasePrefix().str_replace('-', '_', $extension->extensionName()).'_';

        return str_starts_with($tableName, $ownedPrefix);
    }

    private function databasePrefix(): string
    {
        $params = $this->connection->getParams();
        $prefix = $params['system_database_prefix'] ?? null;

        return is_string($prefix) ? TablePrefix::normalize($prefix) : TablePrefix::fromEnvironment();
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

    private function shortName(string $name): string
    {
        return strlen($name) <= 63 ? $name : substr($name, 0, 48).'_'.substr(hash('sha256', $name), 0, 14);
    }
}
