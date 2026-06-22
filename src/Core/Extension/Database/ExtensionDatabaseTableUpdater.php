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
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\TableDiff;
use Throwable;

final readonly class ExtensionDatabaseTableUpdater
{
    public function __construct(
        private Connection $connection,
        private ExtensionDatabaseTableBuilder $tableBuilder,
    ) {
    }

    /**
     * @return WorkflowResult<null>
     */
    public function syncExistingTable(Extension $extension, string $physicalName, ExtensionDatabaseTable $definition): WorkflowResult
    {
        try {
            $existingTable = $this->connection->createSchemaManager()->introspectTable($physicalName);
        } catch (Throwable $error) {
            return $this->invalid($extension, 'table_unreadable', [
                'table' => $physicalName,
                'exception' => $error::class,
                'message' => $error->getMessage(),
            ]);
        }

        $targetTable = $this->tableBuilder->table($extension, $physicalName, $definition);
        $diff = $this->connection->createSchemaManager()->createComparator()->compareTables($existingTable, $targetTable);
        if ($diff->isEmpty()) {
            return WorkflowResult::success(null, ['extension' => $extension->extensionName(), 'table' => $physicalName, 'updated' => false]);
        }

        $validation = $this->validateAdditiveDiff($extension, $physicalName, $diff);
        if (!$validation->isSuccess()) {
            return $validation;
        }

        try {
            $statements = $this->connection->getDatabasePlatform()->getAlterTableSQL($diff);
        } catch (Throwable $error) {
            return WorkflowResult::failed([
                Message::create(
                    ExtensionMessageCode::EXTENSION_DATABASE_CONTRIBUTION_INVALID,
                    ExtensionMessageKey::EXTENSION_DATABASE_CONTRIBUTION_INVALID,
                    ['%reason%' => 'update_table_failed'],
                    ['extension' => $extension->extensionName(), 'table' => $physicalName, 'exception' => $error::class, 'message' => $error->getMessage()],
                    MessageLevel::Exception,
                ),
            ], [
                'extension' => $extension->extensionName(),
                'table' => $physicalName,
            ]);
        }

        if (count($statements) > 1) {
            return $this->invalid($extension, 'multi_statement_update_unsupported', [
                'table' => $physicalName,
                'statement_count' => count($statements),
            ]);
        }

        try {
            foreach ($statements as $sql) {
                $this->connection->executeStatement($sql);
            }
        } catch (Throwable $error) {
            return WorkflowResult::failed([
                Message::create(
                    ExtensionMessageCode::EXTENSION_DATABASE_CONTRIBUTION_INVALID,
                    ExtensionMessageKey::EXTENSION_DATABASE_CONTRIBUTION_INVALID,
                    ['%reason%' => 'update_table_failed'],
                    ['extension' => $extension->extensionName(), 'table' => $physicalName, 'exception' => $error::class, 'message' => $error->getMessage()],
                    MessageLevel::Exception,
                ),
            ], [
                'extension' => $extension->extensionName(),
                'table' => $physicalName,
            ]);
        }

        return WorkflowResult::success(null, [
            'extension' => $extension->extensionName(),
            'table' => $physicalName,
            'updated' => true,
        ]);
    }

    /**
     * @return WorkflowResult<null>
     */
    private function validateAdditiveDiff(Extension $extension, string $physicalName, TableDiff $diff): WorkflowResult
    {
        if ([] !== $diff->getChangedColumns()) {
            return $this->invalid($extension, 'non_additive_column_change', ['table' => $physicalName]);
        }

        if ([] !== $diff->getDroppedColumns()) {
            return $this->invalid($extension, 'non_additive_column_drop', ['table' => $physicalName]);
        }

        if ([] !== $diff->getDroppedIndexes() || [] !== $diff->getRenamedIndexes()) {
            return $this->invalid($extension, 'non_additive_index_change', ['table' => $physicalName]);
        }

        if ([] !== $diff->getAddedForeignKeys() || [] !== $diff->getDroppedForeignKeys()) {
            return $this->invalid($extension, 'foreign_key_update_unsupported', ['table' => $physicalName]);
        }

        foreach ($diff->getAddedColumns() as $column) {
            if (!$this->isSafeAddedColumn($column)) {
                return $this->invalid($extension, 'required_column_without_default', [
                    'table' => $physicalName,
                    'column' => $column->getName(),
                ]);
            }
        }

        return WorkflowResult::success(null);
    }

    private function isSafeAddedColumn(Column $column): bool
    {
        return !$column->getNotnull() || null !== $column->getDefault();
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
