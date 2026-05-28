<?php

declare(strict_types=1);

namespace App\Tests\Core\Geo;

use App\Core\Geo\NullGeoIpResolver;
use PHPUnit\Framework\TestCase;

final class NullGeoIpResolverTest extends TestCase
{
    public function testItReturnsNormalizedPlaceholders(): void
    {
        $result = (new NullGeoIpResolver())->resolve('203.0.113.10');

        self::assertSame([
            'city' => 'n/a',
            'state' => 'n/a',
            'country' => 'n/a',
            'continent' => 'n/a',
        ], $result->toArray());
    }
}
