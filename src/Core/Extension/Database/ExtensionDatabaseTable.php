<?php

declare(strict_types=1);

namespace App\Core\Extension\Database;

use App\Core\Message\MessageException;
use App\Core\Extension\ExtensionMessageKey;

final readonly class ExtensionDatabaseTable
{
    /**
     * @param list<ExtensionDatabaseColumn> $columns
     * @param list<string> $primaryKey
     * @param list<ExtensionDatabaseIndex> $indexes
     */
    public function __construct(
        private string $name,
        private array $columns,
        private array $primaryKey = [],
        private array $indexes = [],
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

        foreach ($primaryKey as $column) {
            $this->assertIdentifier($column, 'column');
        }

        foreach ($indexes as $index) {
            if (!$index instanceof ExtensionDatabaseIndex) {
                throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_DATABASE_CONTRIBUTION_INVALID, [
                    '%reason%' => 'table_index_invalid',
                ]);
            }
        }
    }

    public static function create(string $name, array $columns, array $primaryKey = [], array $indexes = []): self
    {
        return new self($name, $columns, $primaryKey, $indexes);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function physicalName(string $extensionName, string $databasePrefix = ''): string
    {
        $extensionPrefix = str_replace('-', '_', $extensionName).'_';

        return $databasePrefix.$extensionPrefix.$this->name;
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

    private function assertIdentifier(string $value, string $label): void
    {
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $value)) {
            throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_DATABASE_CONTRIBUTION_INVALID, [
                '%reason%' => $label.'_identifier_invalid',
            ], [$label => $value]);
        }
    }
}
