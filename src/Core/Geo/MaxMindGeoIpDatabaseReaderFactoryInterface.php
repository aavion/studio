<?php

declare(strict_types=1);

namespace App\Core\Geo;

interface MaxMindGeoIpDatabaseReaderFactoryInterface
{
    /**
     * @param list<string> $locales
     */
    public function open(string $databasePath, array $locales): MaxMindGeoIpDatabaseReaderInterface;
}
