<?php

declare(strict_types=1);

namespace App\Core\Extension\Database;

use App\Core\Message\MessageException;
use App\Core\Extension\ExtensionMessageKey;

final readonly class ExtensionDatabaseColumn
{
    private const ALLOWED_TYPES = [
        'bigint',
        'boolean',
        'datetime_immutable',
        'float',
        'integer',
        'json',
        'string',
        'text',
    ];

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        private string $name,
        private string $type,
        private array $options = [],
    ) {
        $this->assertIdentifier($name, 'column');

        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_DATABASE_CONTRIBUTION_INVALID, [
                '%reason%' => 'unsupported_column_type',
            ], ['type' => $type]);
        }
    }

    public static function string(string $name, int $length = 255, bool $notNull = true): self
    {
        return new self($name, 'string', ['length' => $length, 'notnull' => $notNull]);
    }

    public static function text(string $name, bool $notNull = true): self
    {
        return new self($name, 'text', ['notnull' => $notNull]);
    }

    public static function integer(string $name, bool $notNull = true): self
    {
        return new self($name, 'integer', ['notnull' => $notNull]);
    }

    public static function boolean(string $name, bool $notNull = true): self
    {
        return new self($name, 'boolean', ['notnull' => $notNull]);
    }

    public static function json(string $name, bool $notNull = true): self
    {
        return new self($name, 'json', ['notnull' => $notNull]);
    }

    public static function datetime(string $name, bool $notNull = true): self
    {
        return new self($name, 'datetime_immutable', ['notnull' => $notNull]);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function type(): string
    {
        return $this->type;
    }

    /**
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return $this->options;
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
