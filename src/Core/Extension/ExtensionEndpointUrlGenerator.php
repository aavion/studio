<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Filesystem\PathGuard;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Throwable;

final readonly class ExtensionEndpointUrlGenerator
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private PathGuard $pathGuard = new PathGuard(),
    ) {
    }

    /**
     * @param array<string, mixed> $params
     */
    public function liveUrl(string $extensionName, string $endpoint, array $params = []): ?string
    {
        if (!ExtensionManifestSpec::isValidSlug($extensionName)) {
            return null;
        }

        try {
            return $this->urlGenerator->generate('api_live_extension_dispatch', [
                ...$params,
                'extensionSlug' => $extensionName,
                'resourcePath' => $this->endpoint($endpoint),
            ]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    public function apiUrl(string $extensionName, string $endpoint, array $params = []): ?string
    {
        if (!ExtensionManifestSpec::isValidSlug($extensionName)) {
            return null;
        }

        try {
            return $this->urlGenerator->generate('api_v1_endpoint_dispatch', [
                ...$params,
                'resourcePath' => 'extensions/'.$extensionName.'/'.$this->endpoint($endpoint),
            ]);
        } catch (Throwable) {
            return null;
        }
    }

    private function endpoint(string $endpoint): string
    {
        return $this->pathGuard->relativePath($endpoint);
    }
}
