<?php

declare(strict_types=1);

namespace App\Core\Geo;

use Throwable;

final readonly class GeoIpResolver implements GeoIpResolverInterface
{
    /** @var list<GeoIpProviderInterface> */
    private array $providers;

    /**
     * @param iterable<GeoIpProviderInterface> $providers
     */
    public function __construct(
        iterable $providers = [],
        private GeoIpProviderInterface $fallbackProvider = new NullGeoIpProvider(),
    ) {
        $this->providers = $this->normalizeProviders($providers);
    }

    public function resolve(?string $ipAddress): GeoIpResult
    {
        if (null === $ipAddress || '' === trim($ipAddress)) {
            return $this->fallbackProvider->resolve($ipAddress);
        }

        $provider = $this->activeProvider();
        if (null === $provider) {
            return $this->fallbackProvider->resolve($ipAddress);
        }

        try {
            return $provider->resolve($ipAddress);
        } catch (Throwable) {
            return $this->fallbackProvider->resolve($ipAddress);
        }
    }

    public function status(): GeoIpProviderStatus
    {
        return $this->activeProvider()?->status() ?? $this->fallbackProvider->status();
    }

    private function activeProvider(): ?GeoIpProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider === $this->fallbackProvider) {
                continue;
            }

            if ($provider->status()->isReady()) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * @param iterable<GeoIpProviderInterface> $providers
     *
     * @return list<GeoIpProviderInterface>
     */
    private function normalizeProviders(iterable $providers): array
    {
        $normalized = [];

        foreach ($providers as $provider) {
            $normalized[] = $provider;
        }

        return $normalized;
    }
}
