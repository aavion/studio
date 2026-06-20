<?php

declare(strict_types=1);

namespace App\Core\Extension\Database;

use App\Core\Extension\ExtensionOwnerName;
use App\Core\Message\MessageException;
use App\Core\Extension\ExtensionMessageKey;

final readonly class ExtensionDatabaseTable
{
    /**
     * @param list<ExtensionDatabaseColumn> $columns
     * @param list<string> $primaryKey
     * @param list<ExtensionDatabaseIndex> $indexes
     * @param list<ExtensionDatabaseForeignKey> $foreignKeys
     */
    public function __construct(
        private string $name,
        private array $columns,
        private array $primaryKey = [],
        private array $indexes = [],
        private array $foreignKeys = [],
    ) {
        $this->assertIdentifier($name, 'table');

        if ([] === $columns) {
            throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_DATABASE_CONTRIBUTION_INVALID, [
                '%reason%' => 'table_columns_empty',
            ]);
        }

        foreach ($columns as $column) {
            if (!$column instanceof ExtensionDatabaseColumn) {
                throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_DATABASE_CONTRIBUTION_INVALID, [
                    '%reason%' => 'table_column_invalid',
                ]);
            }
        }

        $columnNames = [];
        foreach ($columns as $column) {
            $columnNames[$column->name()] = true;
        }

        foreach ($primaryKey as $column) {
            $this->assertIdentifier($column, 'column');
            $this->assertKnownColumn($column, $columnNames, 'primary_key_column_missing');
        }

        foreach ($indexes as $index) {
            if (!$index instanceof ExtensionDatabaseIndex) {
                throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_DATABASE_CONTRIBUTION_INVALID, [
                    '%reason%' => 'table_index_invalid',
                ]);
            }

            foreach ($index->columns() as $column) {
                $this->assertKnownColumn($column, $columnNames, 'index_column_missing');
            }
        }

        foreach ($foreignKeys as $foreignKey) {
            if (!$foreignKey instanceof ExtensionDatabaseForeignKey) {
                throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_DATABASE_CONTRIBUTION_INVALID, [
                    '%reason%' => 'table_foreign_key_invalid',
                ]);
            }

            foreach ($foreignKey->localColumns() as $column) {
                $this->assertKnownColumn($column, $columnNames, 'foreign_key_local_column_missing');
            }
        }
    }

    public static function create(string $name, array $columns, array $primaryKey = [], array $indexes = [], array $foreignKeys = []): self
    {
        return new self($name, $columns, $primaryKey, $indexes, $foreignKeys);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function physicalName(string $extensionName, string $databasePrefix = ''): string
    {
        return $databasePrefix.ExtensionOwnerName::prefix($extensionName).$this->name;
    }

    /**
     * @return list<ExtensionDatabaseColumn>
     */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * @return list<string>
     */
    public function primaryKey(): array
    {
        return $this->primaryKey;
    }

    /**
     * @return list<ExtensionDatabaseIndex>
     */
    public function indexes(): array
    {
        return $this->indexes;
    }

    /**
     * @return list<ExtensionDatabaseForeignKey>
     */
    public function foreignKeys(): array
    {
        return $this->foreignKeys;
    }

    private function assertIdentifier(string $value, string $label): void
    {
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $value)) {
            throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_DATABASE_CONTRIBUTION_INVALID, [
                '%reason%' => $label.'_identifier_invalid',
            ], [$label => $value]);
        }
    }

    /**
     * @param array<string, true> $columnNames
     */
    private function assertKnownColumn(string $column, array $columnNames, string $reason): void
    {
        if (isset($columnNames[$column])) {
            return;
        }

        throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_DATABASE_CONTRIBUTION_INVALID, [
            '%reason%' => $reason,
        ], ['column' => $column]);
    }
}
