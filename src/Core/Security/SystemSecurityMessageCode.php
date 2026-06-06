<?php

declare(strict_types=1);

namespace App\Core\Security;

final class SystemSecurityMessageCode
{
    public const SYSTEM_SECRET_PAYLOAD_ROOT_SECRET_EMPTY = 'system.secret_payload.root_secret_empty';
    public const SYSTEM_SECRET_PAYLOAD_CONTEXT_EMPTY = 'system.secret_payload.context_empty';
    public const SYSTEM_SECRET_PAYLOAD_INVALID = 'system.secret_payload.invalid';
    public const SYSTEM_SECRET_PAYLOAD_ENCRYPT_FAILED = 'system.secret_payload.encrypt_failed';
    public const SYSTEM_SECRET_PAYLOAD_DECRYPT_FAILED = 'system.secret_payload.decrypt_failed';
    public const SYSTEM_SECRET_PAYLOAD_KEY_DERIVATION_FAILED = 'system.secret_payload.key_derivation_failed';
}
