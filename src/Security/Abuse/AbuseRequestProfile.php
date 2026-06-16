<?php

declare(strict_types=1);

namespace App\Security\Abuse;

final readonly class AbuseRequestProfile
{
    public function __construct(
        private RequestFamily $family,
        private RequestIntent $intent,
        private string $method,
        private string $path,
        private string $route,
        private bool $prefetch = false,
        private bool $suspiciousProbe = false,
    ) {
    }

    public function family(): RequestFamily
    {
        return $this->family;
    }

    public function intent(): RequestIntent
    {
        return $this->intent;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function route(): string
    {
        return $this->route;
    }

    public function prefetch(): bool
    {
        return $this->prefetch;
    }

    public function suspiciousProbe(): bool
    {
        return $this->suspiciousProbe;
    }

    /**
     * @return array{family: string, intent: string, method: string, path: string, route: string, prefetch: bool, suspicious_probe: bool}
     */
    public function toArray(): array
    {
        return [
            'family' => $this->family->value,
            'intent' => $this->intent->value,
            'method' => $this->method,
            'path' => $this->path,
            'route' => $this->route,
            'prefetch' => $this->prefetch,
            'suspicious_probe' => $this->suspiciousProbe,
        ];
    }
}
