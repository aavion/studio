<?php

declare(strict_types=1);

namespace App\Core\Extension\Database;

use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;
use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Workflow\WorkflowResult;
use App\Entity\Extension;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Throwable;

final readonly class ExtensionDatabaseReferenceValidator
{
    public function __construct(
        private Connection $connection,
        private ExtensionDatabaseTableNameResolver $names,
    ) {
    }

    /**
     * @param array<string, ExtensionDatabaseTable> $pendingTables
     * @param list<string> $knownTables
     *
     * @return WorkflowResult<null>
     */
    public function validatePendingTables(Extension $extension, array $pendingTables, array $knownTables): WorkflowResult
    {
        foreach ($pendingTables as $physicalName => $table) {
            foreach ($table->foreignKeys() as $foreignKey) {
                $referencedPhysicalName = $this->names->referencedTableName($extension, $foreignKey);

                if (!$this->names->isPortableIdentifier($referencedPhysicalName)) {
                    return $this->invalid($extension, 'foreign_key_reference_name_too_long', [
                        'table' => $physicalName,
                        'foreign_key' => $foreignKey->name(),
                        'referenced_table' => $referencedPhysicalName,
                        'max_length' => ExtensionDatabaseTableNameResolver::MAX_IDENTIFIER_LENGTH,
                    ]);
                }

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
