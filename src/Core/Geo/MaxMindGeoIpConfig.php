<?php

declare(strict_types=1);

namespace App\Core\Geo;

use App\Core\Config\Config;

final readonly class MaxMindGeoIpConfig
{
    public const PROVIDER_KEY = 'maxmind';
    public const DATABASE_EDITION = 'GeoLite2-City';
    public const ENABLED_KEY = 'statistics.geoip.enabled';
    public const DATABASE_PATH_KEY = 'statistics.geoip.maxmind.database_path';
    public const LICENSE_KEY_KEY = 'statistics.geoip.maxmind.license_key';

    public const DEFAULT_DATABASE_PATH = 'var/geoip2/GeoLite2-City.mmdb';

    public function __construct(private Config $config)
    {
    }

    public function enabled(): bool
    {
        return true === $this->config->get(self::ENABLED_KEY, false);
    }

    public function databasePath(): string
    {
        $path = $this->config->get(self::DATABASE_PATH_KEY, self::DEFAULT_DATABASE_PATH);

        return is_string($path) && '' !== trim($path) ? trim($path) : self::DEFAULT_DATABASE_PATH;
    }

    /**
     * @return list<string>
     */
    public function locales(): array
    {
        $defaultLanguage = $this->config->get('localization.default_language', 'en');
        $defaultLanguage = is_string($defaultLanguage) ? trim($defaultLanguage) : 'en';

        if (1 !== preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $defaultLanguage)) {
            $defaultLanguage = 'en';
        }

        return 'en' === $defaultLanguage ? ['en'] : [$defaultLanguage, 'en'];
    }

    public function databaseAbsolutePath(string $projectDir): ?string
    {
        $relativePath = str_replace('\\', '/', $this->databasePath());

        if (
            '' === trim($relativePath)
            || str_contains($relativePath, "\0")
            || str_starts_with($relativePath, '/')
            || str_starts_with($relativePath, '//')
            || str_starts_with($relativePath, '\\\\')
            || 1 === preg_match('/^[A-Za-z]:/', $relativePath)
            || str_contains('/'.$relativePath.'/', '/../')
        ) {
            return null;
        }

        return rtrim($projectDir, DIRECTORY_SEPARATOR.'/\\')
            .DIRECTORY_SEPARATOR
            .str_replace('/', DIRECTORY_SEPARATOR, ltrim($relativePath, '/'));
    }

    public function licenseKey(): string
    {
        $licenseKey = $this->config->get(self::LICENSE_KEY_KEY, '');

        return is_string($licenseKey) ? trim($licenseKey) : '';
    }

    public function hasLicenseKey(): bool
    {
        return '' !== $this->licenseKey();
    }

    public function downloadUrl(): string
    {
        return sprintf(
            'https://download.maxmind.com/app/geoip_download?edition_id=%s&license_key=%s&suffix=tar.gz',
            rawurlencode(self::DATABASE_EDITION),
            rawurlencode($this->licenseKey()),
        );
    }
}
