<?php

declare(strict_types=1);

namespace App\Core\Geo;

interface GeoIpProviderInterface
{
    public function key(): string;

    public function status(): GeoIpProviderStatus;

    public function resolve(?string $ipAddress): GeoIpResult;
}
