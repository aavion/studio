<?php

declare(strict_types=1);

namespace App\Core\AdminAcl;

enum AdminPermissionState: string
{
    case Denied = 'denied';
    case Visible = 'visible';
    case Mutable = 'mutable';

    public function rank(): int
    {
        return match ($this) {
            self::Denied => 0,
            self::Visible => 1,
            self::Mutable => 2,
        };
    }

    public function isVisible(): bool
    {
        return $this->rank() >= self::Visible->rank();
    }

    public function isMutable(): bool
    {
        return self::Mutable === $this;
    }

    public static function fromMixed(mixed $value, self $default = self::Denied): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? $default) : $default;
    }

    public static function max(self $left, self $right): self
    {
        return $left->rank() >= $right->rank() ? $left : $right;
    }
}
