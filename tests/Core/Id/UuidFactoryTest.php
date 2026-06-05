<?php

declare(strict_types=1);

namespace App\Tests\Core\Id;

use App\Core\Id\UuidFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class UuidFactoryTest extends TestCase
{
    public function testItGeneratesValidUuidV7Identifiers(): void
    {
        $uuid = (new UuidFactory())->generate();

        self::assertTrue(Uuid::isValid($uuid));
        self::assertSame('7', $uuid[14]);
    }
}
