<?php

declare(strict_types=1);

namespace App\Core\Geo;

final readonly class GeoIpResult
{
    public const PLACEHOLDER = 'n/a';

    public function __construct(
        public string $city = self::PLACEHOLDER,
        public string $state = self::PLACEHOLDER,
        public string $country = self::PLACEHOLDER,
        public string $continent = self::PLACEHOLDER,
    ) {
    }

    /**
     * @return array{city: string, state: string, country: string, continent: string}
     */
    public function toArray(): array
    {
        return [
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
            'continent' => $this->continent,
        ];
    }
}
