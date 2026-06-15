<?php

declare(strict_types=1);

namespace App\Core\Geo;

final readonly class GeoIpMessageCode
{
    public const GEOIP_DOWNLOAD_MISSING_LICENSE_KEY = 'geoip.download.missing_license_key';
    public const GEOIP_DOWNLOAD_INVALID_LICENSE_KEY = 'geoip.download.invalid_license_key';
    public const GEOIP_DOWNLOAD_SERVER_UNREACHABLE = 'geoip.download.server_unreachable';
    public const GEOIP_DOWNLOAD_FAILED = 'geoip.download.failed';
    public const GEOIP_DOWNLOAD_ARCHIVE_INVALID = 'geoip.download.archive_invalid';
    public const GEOIP_DOWNLOAD_DATABASE_MISSING = 'geoip.download.database_missing';
    public const GEOIP_DOWNLOAD_DATABASE_INVALID = 'geoip.download.database_invalid';
    public const GEOIP_DOWNLOAD_WRITE_FAILED = 'geoip.download.write_failed';
}
