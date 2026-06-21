<?php

declare(strict_types=1);

namespace App\Core\Geo;

final readonly class GeoIpMessageKey
{
    public const GEOIP_DOWNLOAD_MISSING_LICENSE_KEY = 'message.geoip.download.missing_license_key';
    public const GEOIP_DOWNLOAD_INVALID_LICENSE_KEY = 'message.geoip.download.invalid_license_key';
    public const GEOIP_DOWNLOAD_SERVER_UNREACHABLE = 'message.geoip.download.server_unreachable';
    public const GEOIP_DOWNLOAD_FAILED = 'message.geoip.download.failed';
    public const GEOIP_DOWNLOAD_ARCHIVE_INVALID = 'message.geoip.download.archive_invalid';
    public const GEOIP_DOWNLOAD_DATABASE_MISSING = 'message.geoip.download.database_missing';
    public const GEOIP_DOWNLOAD_DATABASE_INVALID = 'message.geoip.download.database_invalid';
    public const GEOIP_DOWNLOAD_WRITE_FAILED = 'message.geoip.download.write_failed';
    public const GEOIP_DOWNLOAD_COMPLETED = 'message.geoip.download.completed';
}
