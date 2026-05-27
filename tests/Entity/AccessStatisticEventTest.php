<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AccessStatisticEvent;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AccessStatisticEventTest extends TestCase
{
    public function testItStoresAnonymizedAccessStatisticFields(): void
    {
        $event = new AccessStatisticEvent(
            '00000000-0000-0000-0000-000000000001',
            new DateTimeImmutable('2026-05-27T10:00:00+00:00'),
            str_repeat('a', 64),
            'GET',
            '/docs',
            'content_view',
            200,
            browserFamily: 'safari',
            deviceType: 'mobile',
            isBot: false,
            country: 'DE',
        );

        self::assertSame('00000000-0000-0000-0000-000000000001', $event->uid());
        self::assertSame(str_repeat('a', 64), $event->visitorId());
        self::assertSame('GET', $event->method());
        self::assertSame('/docs', $event->path());
        self::assertSame('content_view', $event->route());
        self::assertSame(200, $event->httpStatus());
        self::assertSame('safari', $event->browserFamily());
        self::assertSame('mobile', $event->deviceType());
        self::assertFalse($event->isBot());
        self::assertSame('DE', $event->country());
    }
}
