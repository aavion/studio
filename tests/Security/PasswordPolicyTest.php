<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\PasswordPolicy;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    public function testItAcceptsPasswordsThatMeetThePolicy(): void
    {
        $policy = new PasswordPolicy();

        self::assertTrue($policy->isValid('Safe1!pass', 'admin', 'admin@example.test'));
    }

    public function testItRejectsWeakRepeatedAndPersonalPasswords(): void
    {
        $policy = new PasswordPolicy();

        self::assertSame(
            [PasswordPolicy::VIOLATION_LENGTH, PasswordPolicy::VIOLATION_COMPLEXITY],
            $policy->violationCodes('short', 'admin', 'admin@example.test'),
        );
        self::assertSame([PasswordPolicy::VIOLATION_REPEATED], $policy->violationCodes('Saaaafe1!!!!', 'admin', 'admin@example.test'));
        self::assertSame([PasswordPolicy::VIOLATION_PERSONAL], $policy->violationCodes('Admin1!pass', 'admin', 'admin@example.test'));
        self::assertSame([PasswordPolicy::VIOLATION_PERSONAL], $policy->violationCodes('Example1!pass', 'user', 'example@example.test'));
    }
}
