<?php

declare(strict_types=1);

namespace App\Core\Extension\Database;

use App\Core\Extension\ExtensionMessageKey;
use App\Core\Message\MessageException;
use App\Core\Validation\IdentifierSpec;

final readonly class ExtensionDatabaseForeignKey
{
    private const ALLOWED_ACTIONS = [
        'CASCADE',
        'NO ACTION',
        'RESTRICT',
        'SET NULL',
    ];

    /**
     * @param list<string> $localColumns
     * @param list<string> $referencedColumns
     * @param array{onDelete?: string, onUpdate?: string} $options
     */
    private function __construct(
        private string $name,
        private array $localColumns,
        private string $referencedTable,
        private array $referencedColumns,
        private array $options = [],
    ) {
        $this->assertIdentifier($name, 'foreign_key');
        $this->assertColumns($localColumns);
        $this->assertColumns($referencedColumns);

        if ([] === $localColumns || [] === $referencedColumns || count($localColumns) !== count($referencedColumns)) {
            throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_DATABASE_CONTRIBUTION_INVALID, [
                '%reason%' => 'foreign_key_columns_invalid',
            ], ['foreign_key' => $name]);
        }

        $this->assertIdentifier($referencedTable, 'referenced_table');

        foreach ($options as $option => $action) {
            if (!in_array($option, ['onDelete', 'onUpdate'], true) || !is_string($action) || !in_array(strtoupper($action), self::ALLOWED_ACTIONS, true)) {
                throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_DATABASE_CONTRIBUTION_INVALID, [
                    '%reason%' => 'foreign_key_action_invalid',
                ], ['foreign_key' => $name, 'option' => $option, 'action' => $action]);
            }
        }
    }

    /**
     * @param list<string> $localColumns
     * @param list<string> $referencedColumns
     * @param array{onDelete?: string, onUpdate?: string} $options
     */
    public static function table(
        string $name,
        array $localColumns,
        string $referencedTable,
        array $referencedColumns,
        array $options = [],
    ): self {
        return new self($name, $localColumns, $referencedTable, $referencedColumns, $options);
    }

    /**
     * @param list<string> $localColumns
     * @param list<string> $referencedColumns
     * @param array{onDelete?: string, onUpdate?: string} $options
     */
    public static function extensionTable(
        string $name,
        array $localColumns,
        string $referencedTable,
        array $referencedColumns,
        array $options = [],
    ): self {
        return self::table($name, $localColumns, $referencedTable, $referencedColumns, $options);
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return list<string>
     */
    public function localColumns(): array
    {
        return $this->localColumns;
    }

    public function referencedTable(): string
    {
        return $this->referencedTable;
    }

    /**
     * @return list<string>
     */
    public function referencedColumns(): array
    {
        return $this->referencedColumns;
    }

    /**
     * @return array{onDelete?: string, onUpdate?: string}
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->options as $key => $value) {
            $options[$key] = strtoupper($value);
        }

        return $options;
    }

    /**
     * @param list<string> $columns
     */
    private function assertColumns(array $columns): void
    {
        foreach ($columns as $column) {
            $this->assertIdentifier($column, 'column');
        }
    }

    private function assertIdentifier(string $value, string $label): void
    {
        if (!IdentifierSpec::isSnakeIdentifier($value)) {
            throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_DATABASE_CONTRIBUTION_INVALID, [
                '%reason%' => $label.'_identifier_invalid',
            ], [$label => $value]);
        }
    }
}
