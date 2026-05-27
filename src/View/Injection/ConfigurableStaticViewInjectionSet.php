<?php

declare(strict_types=1);

namespace App\View\Injection;

use Throwable;

final readonly class ConfigurableStaticViewInjectionSet
{
    /**
     * @param list<ConfigurableStaticViewInjectionRoute> $routes
     */
    public function __construct(
        private string $packageName,
        private string $configKey,
        private ViewSurface $surface,
        private string $defaultBaseSlug,
        private array $routes,
    ) {
    }

    public function packageName(): string
    {
        return $this->packageName;
    }

    public function configKey(): string
    {
        return $this->configKey;
    }

    public function defaultBaseSlug(): string
    {
        return $this->defaultBaseSlug;
    }

    /**
     * @return list<StaticViewInjection>
     */
    public function staticViewInjections(mixed $configuredBaseSlug): array
    {
        $baseSlug = $this->resolveBaseSlug($configuredBaseSlug);
        $injections = [];

        foreach ($this->routes as $route) {
            $injections[] = $route->toStaticViewInjection($this->surface, $baseSlug);
        }

        return $injections;
    }

    private function resolveBaseSlug(mixed $configuredBaseSlug): string
    {
        $baseSlug = is_string($configuredBaseSlug) ? trim($configuredBaseSlug, '/') : '';
        $baseSlug = '' === $baseSlug ? trim($this->defaultBaseSlug, '/') : $baseSlug;

        try {
            new StaticViewInjection(
                'configurable-route-validation',
                $this->surface,
                $baseSlug,
                'configurable.route.validation',
                '@frontend/empty.html.twig',
            );

            return $baseSlug;
        } catch (Throwable) {
            return trim($this->defaultBaseSlug, '/');
        }
    }
}
