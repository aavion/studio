<?php

declare(strict_types=1);

namespace App\Core\Geo;

final readonly class GeoIpResult
{
    public function __construct(
        public string $city = 'n/a',
        public string $state = 'n/a',
        public string $country = 'n/a',
        public string $continent = 'n/a',
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
