<?php

declare(strict_types=1);

namespace App\Tests\Core\Config;

use App\Core\Config\ConfigValidationGuard;
use PHPUnit\Framework\TestCase;

final class ConfigValidationGuardTest extends TestCase
{
    public function testItBoundsIntegerValues(): void
    {
        $guard = new ConfigValidationGuard();

        self::assertSame(7, $guard->boundedInteger(1, 10, 7, 30));
        self::assertSame(30, $guard->boundedInteger(365, 10, 7, 30));
        self::assertSame(14, $guard->boundedInteger('14', 10, 7, 30));
        self::assertSame(10, $guard->boundedInteger('invalid', 10, 7, 30));
    }
}
