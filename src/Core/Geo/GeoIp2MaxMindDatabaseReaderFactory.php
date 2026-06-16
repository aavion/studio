<?php

declare(strict_types=1);

namespace App\Core\Geo;

use GeoIp2\Database\Reader;

final readonly class GeoIp2MaxMindDatabaseReaderFactory implements MaxMindGeoIpDatabaseReaderFactoryInterface
{
    public function open(string $databasePath, array $locales): MaxMindGeoIpDatabaseReaderInterface
    {
        return new GeoIp2MaxMindDatabaseReader(new Reader($databasePath, $locales));
    }
}
