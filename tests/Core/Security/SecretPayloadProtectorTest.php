<?php

declare(strict_types=1);

namespace App\Tests\Core\Security;

use App\Core\Message\MessageKey;
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

        $this->expectExceptionMessage(MessageKey::SYSTEM_SECRET_PAYLOAD_DECRYPT_FAILED);

        $protector->reveal($payload, 'other.context', 'owner-id');
    }

    public function testItRejectsDifferentAssociatedData(): void
    {
        $protector = new SecretPayloadProtector('runtime-secret');
        $payload = $protector->protect('plain-secret', 'test.context', 'owner-id');

        $this->expectExceptionMessage(MessageKey::SYSTEM_SECRET_PAYLOAD_DECRYPT_FAILED);

        $protector->reveal($payload, 'test.context', 'other-owner-id');
    }

    public function testItRejectsInvalidPayloads(): void
    {
        $protector = new SecretPayloadProtector('runtime-secret');

        $this->expectExceptionMessage(MessageKey::SYSTEM_SECRET_PAYLOAD_INVALID);

        $protector->reveal('v1.not-valid', 'test.context');
    }

    public function testItBuildsContextBoundHmacs(): void
    {
        $protector = new SecretPayloadProtector('runtime-secret');

        self::assertSame($protector->hmac('plain-secret', 'test.context'), $protector->hmac('plain-secret', 'test.context'));
        self::assertNotSame($protector->hmac('plain-secret', 'test.context'), $protector->hmac('plain-secret', 'other.context'));
    }

    public function testItRejectsEmptyRootSecrets(): void
    {
        $this->expectExceptionMessage(MessageKey::SYSTEM_SECRET_PAYLOAD_ROOT_SECRET_EMPTY);

        new SecretPayloadProtector('');
    }

    public function testItRejectsEmptyContexts(): void
    {
        $protector = new SecretPayloadProtector('runtime-secret');

        $this->expectExceptionMessage(MessageKey::SYSTEM_SECRET_PAYLOAD_CONTEXT_EMPTY);

        $protector->protect('plain-secret', '');
    }
}
