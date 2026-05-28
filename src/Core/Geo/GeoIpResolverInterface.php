<?php

declare(strict_types=1);

namespace App\Core\Geo;

interface GeoIpResolverInterface
{
    public function resolve(?string $ipAddress): GeoIpResult;
}
