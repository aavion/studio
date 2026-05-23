<?php

declare(strict_types=1);

namespace App\Core\Access;

enum AccessCapability: string
{
    case View = 'view';
    case Use = 'use';
    case Edit = 'edit';
    case Manage = 'manage';

    public function defaultMinLevel(): int
    {
        return match ($this) {
            self::View, self::Use => AccessLevel::DEFAULT_VIEW,
            self::Edit => AccessLevel::DEFAULT_EDIT,
            self::Manage => AccessLevel::DEFAULT_MANAGE,
        };
    }
}
