<?php

declare(strict_types=1);

namespace App\Core\Geo;

final readonly class NullGeoIpResolver implements GeoIpResolverInterface
{
    public function resolve(?string $ipAddress): GeoIpResult
    {
        return new GeoIpResult();
    }
}
