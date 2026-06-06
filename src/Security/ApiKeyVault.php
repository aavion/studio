<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Security\SecretPayloadProtector;

final readonly class ApiKeyVault
{
    private const HMAC_CONTEXT = 'security.api_key.hmac';
    private const PAYLOAD_CONTEXT = 'security.api_key.payload';

    public function __construct(private SecretPayloadProtector $protector)
    {
    }

    public function generatePlainKey(string $prefix): string
    {
        return $prefix.'.'.bin2hex(random_bytes(24));
    }

    public function hmac(string $plainKey): string
    {
        return $this->protector->hmac($plainKey, self::HMAC_CONTEXT);
    }

    public function encrypt(string $plainKey, string $prefix): string
    {
        return $this->protector->protect($plainKey, self::PAYLOAD_CONTEXT, $prefix);
    }

    public function decrypt(string $payload, string $prefix): ?string
    {
        try {
            return $this->protector->reveal($payload, self::PAYLOAD_CONTEXT, $prefix);
        } catch (\RuntimeException|\InvalidArgumentException) {
            return null;
        }
    }
}
