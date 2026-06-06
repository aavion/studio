<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\PasswordPolicy;
use App\Security\PasswordPolicyErrorMapper;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyErrorMapperTest extends TestCase
{
    public function testItMapsPolicyViolationsToUserFacingErrorKeys(): void
    {
        $mapper = new PasswordPolicyErrorMapper(new PasswordPolicy());

        self::assertSame([
            'ui.user.password.errors.new_password_length',
            'ui.user.password.errors.new_password_complexity',
        ], $mapper->errorKeys('short', 'admin', 'admin@example.test'));
        self::assertSame([
            'ui.user.password.errors.new_password_repeated',
        ], $mapper->errorKeys('Saaaafe1!!!!', 'admin', 'admin@example.test'));
        self::assertSame([
            'ui.user.password.errors.new_password_personal',
        ], $mapper->errorKeys('Admin1!pass', 'admin', 'admin@example.test'));
    }
}
