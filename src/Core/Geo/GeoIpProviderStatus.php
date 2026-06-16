<?php

declare(strict_types=1);

namespace App\Core\Geo;

final readonly class GeoIpProviderStatus
{
    public const READY = 'ready';
    public const DISABLED = 'disabled';
    public const UNCONFIGURED = 'unconfigured';
    public const UNAVAILABLE = 'unavailable';

    public function __construct(
        public string $providerKey,
        public string $status,
        public ?string $databaseEdition = null,
        public ?string $databaseBuildDate = null,
        public ?string $failureCode = null,
    ) {
    }

    public static function ready(
        string $providerKey,
        ?string $databaseEdition = null,
        ?string $databaseBuildDate = null,
    ): self {
        return new self(
            $providerKey,
            self::READY,
            $databaseEdition,
            $databaseBuildDate,
        );
    }

    public static function disabled(string $providerKey): self
    {
        return new self($providerKey, self::DISABLED);
    }

    public static function unconfigured(string $providerKey, ?string $failureCode = null): self
    {
        return new self($providerKey, self::UNCONFIGURED, failureCode: $failureCode);
    }

    public static function unavailable(string $providerKey, ?string $failureCode = null): self
    {
        return new self($providerKey, self::UNAVAILABLE, failureCode: $failureCode);
    }

    public function isReady(): bool
    {
        return self::READY === $this->status;
    }

    /**
     * @return array{
     *     provider_key: string,
     *     status: string,
     *     database_edition: ?string,
     *     database_build_date: ?string,
     *     failure_code: ?string
     * }
     */
    public function toSafeArray(): array
    {
        return [
            'provider_key' => $this->providerKey,
            'status' => $this->status,
            'database_edition' => $this->databaseEdition,
            'database_build_date' => $this->databaseBuildDate,
            'failure_code' => $this->failureCode,
        ];
    }
}
