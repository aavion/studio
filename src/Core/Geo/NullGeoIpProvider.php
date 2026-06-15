<?php

declare(strict_types=1);

namespace App\Core\Geo;

final readonly class NullGeoIpProvider implements GeoIpProviderInterface
{
    public const KEY = 'none';

    public function key(): string
    {
        return self::KEY;
    }

    public function status(): GeoIpProviderStatus
    {
        return GeoIpProviderStatus::disabled(self::KEY);
    }

    public function resolve(?string $ipAddress): GeoIpResult
    {
        return new GeoIpResult();
    }
}
