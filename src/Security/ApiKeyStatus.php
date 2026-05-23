<?php

declare(strict_types=1);

namespace App\Security;

enum ApiKeyStatus: string
{
    case ReadWrite = 'read_write';
    case ReadOnly = 'read_only';
    case Revoked = 'revoked';
}
