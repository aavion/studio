<?php

declare(strict_types=1);

namespace App\Tests\Core\Security;

use App\Core\Security\SecretPayloadProtector;
use PHPUnit\Framework\TestCase;

final class SecretPayloadProtectorTest extends TestCase
{
    public function testItProtectsAndRevealsContextBoundPayloads(): void
    {
        $protector = new SecretPayloadProtector('runtime-secret');
        $payload = $protector->protect('plain-secret', 'test.context', 'owner-id');

        self::assertStringStartsWith('v1.', $payload);
        self::assertStringNotContainsString('plain-secret', $payload);
        self::assertSame('plain-secret', $protector->reveal($payload, 'test.context', 'owner-id'));
    }

    public function testItRejectsDifferentContexts(): void
    {
        $protector = new SecretPayloadProtector('runtime-secret');
        $payload = $protector->protect('plain-secret', 'test.context', 'owner-id');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Secret payload could not be decrypted.');

        $protector->reveal($payload, 'other.context', 'owner-id');
    }

    public function testItRejectsDifferentAssociatedData(): void
    {
        $protector = new SecretPayloadProtector('runtime-secret');
        $payload = $protector->protect('plain-secret', 'test.context', 'owner-id');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Secret payload could not be decrypted.');

        $protector->reveal($payload, 'test.context', 'other-owner-id');
    }

    public function testItRejectsInvalidPayloads(): void
    {
        $protector = new SecretPayloadProtector('runtime-secret');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Secret payload is invalid.');

        $protector->reveal('v1.not-valid', 'test.context');
    }

    public function testItRejectsEmptyRootSecrets(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Secret payload root secret must not be empty.');

        new SecretPayloadProtector('');
    }

    public function testItRejectsEmptyContexts(): void
    {
        $protector = new SecretPayloadProtector('runtime-secret');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Secret payload context must not be empty.');

        $protector->protect('plain-secret', '');
    }
}
