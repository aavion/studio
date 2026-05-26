<?php

declare(strict_types=1);

namespace App\Core\Config;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Throwable;

final readonly class Config
{
    public function __construct(private Connection $connection)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        try {
            $value = $this->connection->fetchOne('SELECT value FROM config_entry WHERE config_key = ?', [$key]);
        } catch (Throwable) {
            return $default;
        }

        if (!is_string($value)) {
            return $default;
        }

        try {
            return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $default;
        }
    }

    public function set(
        string $key,
        mixed $value,
        ?ConfigValueType $type = null,
        bool $sensitive = false,
        ?string $modifiedBy = null,
    ): void {
        $values = [
            'value' => json_encode($value, JSON_THROW_ON_ERROR),
            'value_type' => ($type ?? $this->typeFor($value))->value,
            'sensitive' => $sensitive ? 1 : 0,
            'modified_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'modified_by' => $modifiedBy,
        ];

        $this->connection->fetchOne('SELECT config_key FROM config_entry WHERE config_key = ?', [$key])
            ? $this->connection->update('config_entry', $values, ['config_key' => $key])
            : $this->connection->insert('config_entry', ['config_key' => $key, ...$values]);
    }

    private function typeFor(mixed $value): ConfigValueType
    {
        return match (true) {
            is_bool($value) => ConfigValueType::Boolean,
            is_int($value) => ConfigValueType::Integer,
            is_float($value) => ConfigValueType::Float,
            is_string($value) => ConfigValueType::String,
            default => ConfigValueType::Json,
        };
    }
}
