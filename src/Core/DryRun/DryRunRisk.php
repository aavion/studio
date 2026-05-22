<?php

declare(strict_types=1);

namespace App\Core\DryRun;

enum DryRunRisk: int
{
    case Low = 1;
    case Medium = 2;
    case High = 3;

    public function label(): string
    {
        return match ($this) {
            self::Low => 'low',
            self::Medium => 'medium',
            self::High => 'high',
        };
    }
}
