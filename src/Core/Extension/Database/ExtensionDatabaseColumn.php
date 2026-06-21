<?php

declare(strict_types=1);

namespace App\Core\Extension\Database;

use App\Core\Extension\ExtensionMessageKey;
use App\Core\Message\MessageException;
use App\Core\Validation\IdentifierSpec;

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

    private const ALLOWED_OPTIONS = [
        'default',
        'length',
        'notnull',
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

        $this->assertOptions($options);
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
        if (!IdentifierSpec::isPortableDatabaseIdentifier($value)) {
            throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_DATABASE_CONTRIBUTION_INVALID, [
                '%reason%' => $label.'_identifier_invalid',
            ], [$label => $value]);
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function assertOptions(array $options): void
    {
        foreach ($options as $name => $value) {
            if (!in_array($name, self::ALLOWED_OPTIONS, true)) {
                throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_DATABASE_CONTRIBUTION_INVALID, [
                    '%reason%' => 'unsupported_column_option',
                ], ['option' => $name]);
            }

            if ('notnull' === $name && !is_bool($value)) {
                throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_DATABASE_CONTRIBUTION_INVALID, [
                    '%reason%' => 'invalid_column_option',
                ], ['option' => $name]);
            }

            if ('length' === $name && (!is_int($value) || $value < 1 || $value > 4096)) {
                throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_DATABASE_CONTRIBUTION_INVALID, [
                    '%reason%' => 'invalid_column_option',
                ], ['option' => $name]);
            }

            if ('default' === $name && null !== $value && !is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
                throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_DATABASE_CONTRIBUTION_INVALID, [
                    '%reason%' => 'invalid_column_option',
                ], ['option' => $name]);
            }
        }
    }
}
