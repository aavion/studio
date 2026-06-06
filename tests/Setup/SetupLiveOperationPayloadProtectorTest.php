<?php

declare(strict_types=1);

namespace App\Tests\Setup;

use App\Core\Security\SecretPayloadProtector;
use App\Setup\SetupLiveOperationPayloadProtector;
use PHPUnit\Framework\TestCase;

final class SetupLiveOperationPayloadProtectorTest extends TestCase
{
    public function testItEncryptsSetupSecretsBeforePersistingLiveOperationPayload(): void
    {
        $protector = new SetupLiveOperationPayloadProtector(new SecretPayloadProtector('runtime-secret'));
        $payload = [
            'trigger' => 'setup_wizard',
            'values' => [
                'admin_username' => 'admin',
                'admin_password' => 'Secret1!password',
                'admin_password_confirm' => 'Secret1!password',
                'database_url' => 'mysql://studio:db-secret@127.0.0.1/studio',
                'database_password' => 'db-secret',
                'app_secret' => 'new-app-secret',
            ],
        ];

        $protected = $protector->protect($payload);
        $encoded = json_encode($protected, JSON_THROW_ON_ERROR);

        self::assertIsString($encoded);
        self::assertStringNotContainsString('Secret1!password', $encoded);
        self::assertStringNotContainsString('db-secret', $encoded);
        self::assertStringNotContainsString('new-app-secret', $encoded);
        self::assertSame('[protected]', $protected['values']['admin_password']);
        self::assertSame($payload, $protector->unprotect($protected));
    }

    public function testItLeavesPlainPayloadsReadableForExistingInternalCallers(): void
    {
        $protector = new SetupLiveOperationPayloadProtector(new SecretPayloadProtector('runtime-secret'));
        $payload = ['values' => ['admin_password' => 'Secret1!password']];

        self::assertSame($payload, $protector->unprotect($payload));
    }
}
