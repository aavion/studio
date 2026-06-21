<?php

declare(strict_types=1);

namespace App\Core\Config;

final readonly class ConfigValidationGuard
{
    public function boundedInteger(mixed $value, int $default, int $min, int $max): int
    {
        $minimum = min($min, $max);
        $maximum = max($min, $max);
        $integer = is_int($value) ? $value : (is_numeric($value) ? (int) $value : $default);

        return max($minimum, min($maximum, $integer));
    }
}
