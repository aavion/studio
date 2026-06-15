<?php

declare(strict_types=1);

namespace App\Core\Geo;

use GeoIp2\Model\City;
use MaxMind\Db\Reader\Metadata;

interface MaxMindGeoIpDatabaseReaderInterface
{
    public function city(string $ipAddress): City;

    public function metadata(): Metadata;
}
