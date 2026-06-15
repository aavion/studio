<?php

declare(strict_types=1);

namespace App\Core\Geo;

use GeoIp2\Database\Reader;
use GeoIp2\Model\City;
use MaxMind\Db\Reader\Metadata;

final readonly class GeoIp2MaxMindDatabaseReader implements MaxMindGeoIpDatabaseReaderInterface
{
    public function __construct(private Reader $reader)
    {
    }

    public function city(string $ipAddress): City
    {
        return $this->reader->city($ipAddress);
    }

    public function metadata(): Metadata
    {
        return $this->reader->metadata();
    }
}
