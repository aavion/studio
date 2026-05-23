<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Message\MessageKey;

enum ApiKeyStatus: string
{
    case ReadWrite = 'read_write';
    case ReadOnly = 'read_only';
    case Revoked = 'revoked';

    public function messageKey(): string
    {
        return match ($this) {
            self::ReadWrite => MessageKey::API_KEY_STATUS_READ_WRITE,
            self::ReadOnly => MessageKey::API_KEY_STATUS_READ_ONLY,
            self::Revoked => MessageKey::API_KEY_STATUS_REVOKED,
        };
    }

    public function isActive(): bool
    {
        return self::Revoked !== $this;
    }

    public function allowsWrite(): bool
    {
        return self::ReadWrite === $this;
    }
}
