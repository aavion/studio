<?php

declare(strict_types=1);

namespace App\Tests\Core\Validation;

use App\Core\Message\MessageException;
use App\Core\Validation\Uid;
use PHPUnit\Framework\TestCase;

final class UidTest extends TestCase
{
    public function testItAcceptsValidSymfonyUuidValues(): void
    {
        self::assertSame(
            '11111111-1111-7111-8111-111111111111',
            Uid::assert('11111111-1111-7111-8111-111111111111', 'Test UID'),
        );
    }

    public function testItRejectsUuidShapedInvalidValues(): void
    {
        $this->expectException(MessageException::class);

        Uid::assert('11111111-1111-1111-1111-111111111111', 'Test UID');
    }
}
