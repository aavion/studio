<?php

declare(strict_types=1);

namespace App\Core\AdminAcl;

use App\Core\Access\AccessLevel;

enum AdminPermissionSurface: string
{
    case Admin = 'admin';
    case Editor = 'editor';
    case Frontend = 'frontend';

    public function gateAccessLevel(): int
    {
        return match ($this) {
            self::Admin => AccessLevel::ADMIN,
            self::Editor => AccessLevel::AUTHOR,
            self::Frontend => AccessLevel::PUBLIC,
        };
    }

    public function labelKey(): string
    {
        return 'admin.acl.surfaces.'.$this->value;
    }

    public static function fromFeatureIdentifier(string $identifier): self
    {
        $prefix = strtok($identifier, '.');

        return match ($prefix) {
            'editor' => self::Editor,
            'frontend' => self::Frontend,
            default => self::Admin,
        };
    }
}
