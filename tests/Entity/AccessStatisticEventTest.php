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
            '00000000-0000-7000-8000-000000000001',
            new DateTimeImmutable('2026-05-27T10:00:00+00:00'),
            'request-a',
            str_repeat('a', 64),
            'GET',
            '/docs',
            '/docs',
            'content_view',
            'content_view',
            'public',
            200,
            durationMs: 12,
            browserFamily: 'safari',
            deviceType: 'mobile',
            isBot: false,
            doNotTrack: true,
            referrerHost: 'example.org',
            preferredLanguage: 'de-de',
            responseSize: 42,
            country: 'DE',
        );

        self::assertSame('00000000-0000-7000-8000-000000000001', $event->uid());
        self::assertSame('request-a', $event->requestId());
        self::assertSame(str_repeat('a', 64), $event->visitorId());
        self::assertSame('GET', $event->method());
        self::assertSame('/docs', $event->path());
        self::assertSame('/docs', $event->requestedPath());
        self::assertSame('content_view', $event->route());
        self::assertSame('content_view', $event->resolvedRoute());
        self::assertSame('public', $event->surface());
        self::assertSame(200, $event->httpStatus());
        self::assertSame(12, $event->durationMs());
        self::assertSame('safari', $event->browserFamily());
        self::assertSame('mobile', $event->deviceType());
        self::assertFalse($event->isBot());
        self::assertTrue($event->doNotTrack());
        self::assertSame('example.org', $event->referrerHost());
        self::assertSame('de-de', $event->preferredLanguage());
        self::assertSame(42, $event->responseSize());
        self::assertSame('DE', $event->country());
    }
}
