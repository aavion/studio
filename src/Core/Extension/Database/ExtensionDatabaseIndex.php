<?php

declare(strict_types=1);

namespace App\Core\Extension\Database;

use App\Core\Extension\ExtensionMessageKey;
use App\Core\Message\MessageException;
use App\Core\Validation\IdentifierSpec;

final readonly class ExtensionDatabaseIndex
{
    /**
     * @param list<string> $columns
     */
    public function __construct(
        private string $name,
        private array $columns,
        private bool $unique = false,
    ) {
        $this->assertIdentifier($name, 'index');

        if ([] === $columns) {
            throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_DATABASE_CONTRIBUTION_INVALID, [
                '%reason%' => 'index_columns_empty',
            ]);
        }

        foreach ($columns as $column) {
            $this->assertIdentifier($column, 'column');
        }
    }

    public static function index(string $name, array $columns): self
    {
        return new self($name, $columns);
    }

    public static function uniqueIndex(string $name, array $columns): self
    {
        return new self($name, $columns, true);
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return list<string>
     */
    public function columns(): array
    {
        return $this->columns;
    }

    public function unique(): bool
    {
        return $this->unique;
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
