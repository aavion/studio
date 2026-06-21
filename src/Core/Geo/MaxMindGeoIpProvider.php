<?php

declare(strict_types=1);

namespace App\Core\Geo;

use DateTimeImmutable;
use DateTimeZone;
use GeoIp2\Model\City;
use Throwable;

final class MaxMindGeoIpProvider implements GeoIpProviderInterface
{
    private ?MaxMindGeoIpDatabaseReaderInterface $reader = null;
    private ?GeoIpProviderStatus $status = null;

    public function __construct(
        private readonly MaxMindGeoIpConfig $config,
        private readonly MaxMindGeoIpDatabaseReaderFactoryInterface $readerFactory,
        private readonly string $projectDir,
    ) {
    }

    public function key(): string
    {
        return MaxMindGeoIpConfig::PROVIDER_KEY;
    }

    public function status(): GeoIpProviderStatus
    {
        if (null !== $this->status) {
            return $this->status;
        }

        if (!$this->config->enabled()) {
            return $this->status = GeoIpProviderStatus::disabled($this->key());
        }

        $databasePath = $this->databasePath();
        if (null === $databasePath) {
            return $this->status = GeoIpProviderStatus::unconfigured($this->key(), 'invalid_database_path');
        }

        if (!is_file($databasePath) || !is_readable($databasePath)) {
            return $this->status = GeoIpProviderStatus::unconfigured($this->key(), 'database_missing');
        }

        try {
            $metadata = $this->reader()->metadata();
        } catch (Throwable) {
            return $this->status = GeoIpProviderStatus::unavailable($this->key(), 'database_unreadable');
        }

        if (!$this->databaseSupportsCityLookups($metadata->databaseType ?? null)) {
            return $this->status = GeoIpProviderStatus::unavailable($this->key(), 'database_unsupported');
        }

        return $this->status = GeoIpProviderStatus::ready(
            $this->key(),
            databaseEdition: is_string($metadata->databaseType) ? $metadata->databaseType : null,
            databaseBuildDate: is_int($metadata->buildEpoch) ? $this->formatBuildDate($metadata->buildEpoch) : null,
        );
    }

    public function resolve(?string $ipAddress): GeoIpResult
    {
        if (!$this->status()->isReady() || !$this->isPublicIp($ipAddress)) {
            return new GeoIpResult();
        }

        try {
            return $this->resultFromCity($this->reader()->city((string) $ipAddress));
        } catch (Throwable) {
            return new GeoIpResult();
        }
    }

    private function reader(): MaxMindGeoIpDatabaseReaderInterface
    {
        if (null !== $this->reader) {
            return $this->reader;
        }

        $databasePath = $this->databasePath();
        if (null === $databasePath) {
            throw new \RuntimeException('GeoIP database path is unavailable.');
        }

        return $this->reader = $this->readerFactory->open($databasePath, $this->config->locales());
    }

    private function databasePath(): ?string
    {
        return $this->config->databaseAbsolutePath($this->projectDir);
    }

    private function isPublicIp(?string $ipAddress): bool
    {
        return is_string($ipAddress)
            && false !== filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    private function resultFromCity(City $city): GeoIpResult
    {
        return new GeoIpResult(
            $this->locationValue($city->city->name),
            $this->locationValue($city->mostSpecificSubdivision->name ?? $city->mostSpecificSubdivision->isoCode),
            $this->locationValue($city->country->name ?? $city->country->isoCode ?? $city->registeredCountry->name ?? $city->registeredCountry->isoCode),
            $this->locationValue($city->continent->name ?? $city->continent->code),
        );
    }

    private function locationValue(?string $value): string
    {
        return is_string($value) && '' !== trim($value) ? trim($value) : GeoIpResult::PLACEHOLDER;
    }

    private function databaseSupportsCityLookups(mixed $databaseType): bool
    {
        return is_string($databaseType) && str_contains($databaseType, 'City');
    }

    private function formatBuildDate(int $buildEpoch): string
    {
        return (new DateTimeImmutable('@'.$buildEpoch))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d');
    }
}
