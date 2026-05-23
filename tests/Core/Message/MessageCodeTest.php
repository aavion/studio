<?php

declare(strict_types=1);

namespace App\Tests\Core\Message;

use App\Core\Message\MessageCode;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class MessageCodeTest extends TestCase
{
    public function testItDefinesUniqueCodes(): void
    {
        $values = array_values((new ReflectionClass(MessageCode::class))->getConstants());

        self::assertNotEmpty($values);
        self::assertSame($values, array_unique($values));

        foreach ($values as $value) {
            self::assertIsString($value);
            self::assertNotSame('', trim($value));
        }
    }
}
