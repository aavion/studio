<?php

declare(strict_types=1);

namespace App\Core\Geo;

use App\Core\Config\Config;

final readonly class MaxMindGeoIpConfig
{
    public const PROVIDER_KEY = 'maxmind';
    public const ENABLED_KEY = 'security.geoip.enabled';
    public const SELECTED_PROVIDER_KEY = 'security.geoip.provider';
    public const DATABASE_PATH_KEY = 'security.geoip.maxmind.database_path';
    public const LOCALES_KEY = 'security.geoip.maxmind.locales';
    public const UPDATE_ENABLED_KEY = 'security.geoip.maxmind.update_enabled';
    public const UPDATE_INTERVAL_KEY = 'security.geoip.maxmind.update_interval';
    public const ACCOUNT_ID_KEY = 'security.geoip.maxmind.account_id';
    public const LICENSE_KEY_KEY = 'security.geoip.maxmind.license_key';

    public const DEFAULT_DATABASE_PATH = 'var/geoip/GeoLite2-City.mmdb';
    public const DEFAULT_LOCALES = ['en'];
    public const DEFAULT_UPDATE_INTERVAL = 'weekly';

    public function __construct(private Config $config)
    {
    }

    public function enabled(): bool
    {
        return true === $this->config->get(self::ENABLED_KEY, false)
            && self::PROVIDER_KEY === $this->provider();
    }

    public function provider(): string
    {
        $provider = $this->config->get(self::SELECTED_PROVIDER_KEY, self::PROVIDER_KEY);

        return is_string($provider) && '' !== trim($provider) ? trim($provider) : self::PROVIDER_KEY;
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
        $locales = $this->config->get(self::LOCALES_KEY, self::DEFAULT_LOCALES);
        $locales = is_array($locales) ? $locales : self::DEFAULT_LOCALES;
        $normalized = [];

        foreach ($locales as $locale) {
            if (!is_string($locale)) {
                continue;
            }

            $locale = trim($locale);
            if (1 !== preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $locale)) {
                continue;
            }

            $normalized[$locale] = $locale;
        }

        $normalized['en'] ??= 'en';

        return array_slice(array_values($normalized), 0, 8);
    }

    public function updateEnabled(): bool
    {
        return true === $this->config->get(self::UPDATE_ENABLED_KEY, false);
    }

    public function updateInterval(): string
    {
        $interval = $this->config->get(self::UPDATE_INTERVAL_KEY, self::DEFAULT_UPDATE_INTERVAL);

        return is_string($interval) && in_array($interval, ['manual', 'daily', 'weekly'], true)
            ? $interval
            : self::DEFAULT_UPDATE_INTERVAL;
    }
}
