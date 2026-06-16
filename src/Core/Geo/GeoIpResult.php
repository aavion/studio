<?php

declare(strict_types=1);

namespace App\Core\Geo;

final readonly class GeoIpResult
{
    public const PLACEHOLDER = 'n/a';
    public const MAX_LABEL_LENGTH = 80;

    public string $city;
    public string $state;
    public string $country;
    public string $continent;

    public function __construct(
        string $city = self::PLACEHOLDER,
        string $state = self::PLACEHOLDER,
        string $country = self::PLACEHOLDER,
        string $continent = self::PLACEHOLDER,
    ) {
        $this->city = self::label($city);
        $this->state = self::label($state);
        $this->country = self::label($country);
        $this->continent = self::label($continent);
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

    private static function label(string $value): string
    {
        $value = trim($value);

        if ('' === $value) {
            return self::PLACEHOLDER;
        }

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, self::MAX_LABEL_LENGTH);
        }

        return substr($value, 0, self::MAX_LABEL_LENGTH);
    }
}
