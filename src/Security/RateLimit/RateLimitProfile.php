<?php

declare(strict_types=1);

namespace App\Security\RateLimit;

enum RateLimitProfile: string
{
    case Off = 'off';
    case Standard = 'standard';
    case Strict = 'strict';
    case Panic = 'panic';

    public static function fromMixed(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        return is_string($value) ? (self::tryFrom($value) ?? self::Standard) : self::Standard;
    }

    public function consumesLimiterStorage(): bool
    {
        return self::Off !== $this;
    }

    public function capacityMultiplier(): float
    {
        return match ($this) {
            self::Off, self::Standard => 1.0,
            self::Strict => 0.5,
            self::Panic => 0.25,
        };
    }

    public function windowMultiplier(): float
    {
        return match ($this) {
            self::Off, self::Standard => 1.0,
            self::Strict => 1.5,
            self::Panic => 2.0,
        };
    }

    public function retryAfterMultiplier(): float
    {
        return match ($this) {
            self::Off, self::Standard => 1.0,
            self::Strict => 1.5,
            self::Panic => 2.0,
        };
    }
}
