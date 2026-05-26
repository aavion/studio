<?php

declare(strict_types=1);

namespace App\Core\Config;

use Doctrine\DBAL\Connection;
use Throwable;

final readonly class ConfigReader
{
    public function __construct(private Connection $connection)
    {
    }

    public function bool(string $key, bool $default): bool
    {
        $value = $this->value($key, $default);

        return is_bool($value) ? $value : $default;
    }

    public function int(string $key, int $default): int
    {
        $value = $this->value($key, $default);

        return is_int($value) ? $value : $default;
    }

    public function value(string $key, mixed $default): mixed
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
}
