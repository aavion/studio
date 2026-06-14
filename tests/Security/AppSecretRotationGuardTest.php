<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\AppSecretRotationGuard;
use App\Setup\SetupInputValidator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class AppSecretRotationGuardTest extends TestCase
{
    public function testItRejectsUnsupportedShortAppSecretBeforeRecovery(): void
    {
        $guard = $this->guardWithSecret('short');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf(
            'The configured APP_SECRET is unsupported: it must be at least %d bytes.',
            SetupInputValidator::MIN_APP_SECRET_LENGTH,
        ));

        $guard->handle();
    }

    private function guardWithSecret(string $secret): AppSecretRotationGuard
    {
        $reflection = new ReflectionClass(AppSecretRotationGuard::class);
        $guard = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('secret')->setValue($guard, $secret);

        return $guard;
    }
}
