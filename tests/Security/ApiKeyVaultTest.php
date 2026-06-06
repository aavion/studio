<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Core\Security\SecretPayloadProtector;
use App\Security\ApiKeyVault;
use PHPUnit\Framework\TestCase;

final class ApiKeyVaultTest extends TestCase
{
    public function testItStoresLookupHashesAndEncryptedPayloadsWithSharedSecretProtector(): void
    {
        $vault = new ApiKeyVault(new SecretPayloadProtector('runtime-secret'));
        $plainKey = $vault->generatePlainKey('docs');
        $payload = $vault->encrypt($plainKey, 'docs');

        self::assertStringStartsWith('docs.', $plainKey);
        self::assertSame($vault->hmac($plainKey), $vault->hmac($plainKey));
        self::assertStringNotContainsString($plainKey, $payload);
        self::assertSame($plainKey, $vault->decrypt($payload, 'docs'));
    }

    public function testItRejectsPayloadsBoundToDifferentPrefixes(): void
    {
        $vault = new ApiKeyVault(new SecretPayloadProtector('runtime-secret'));
        $payload = $vault->encrypt('docs.plain-secret', 'docs');

        self::assertNull($vault->decrypt($payload, 'other'));
    }

    public function testItRejectsInvalidPayloads(): void
    {
        $vault = new ApiKeyVault(new SecretPayloadProtector('runtime-secret'));

        self::assertNull($vault->decrypt('invalid', 'docs'));
    }
}
