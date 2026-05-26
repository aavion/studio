<?php

declare(strict_types=1);

namespace App\Content\Routing;

use App\Core\Config\Config;
use App\Localization\TranslationLanguageCatalog;

final readonly class ContentRouteLocalization
{
    public const ENABLED_KEY = 'localization.route_prefixes_enabled';
    public const DEFAULT_LANGUAGE_KEY = 'localization.default_language';
    public const HOME_PATH_KEY = 'content.home_path';

    public function __construct(
        private Config $config,
        private TranslationLanguageCatalog $languageCatalog,
    ) {
    }

    public function isEnabled(): bool
    {
        return true === $this->configValue(self::ENABLED_KEY, false);
    }

    /**
     * @return list<string>
     */
    public function availableLanguages(): array
    {
        return $this->languageCatalog->availableLanguages();
    }

    public function defaultLanguage(): string
    {
        $defaultLanguage = $this->configValue(self::DEFAULT_LANGUAGE_KEY, null);
        $availableLanguages = $this->availableLanguages();

        if (is_string($defaultLanguage) && in_array($defaultLanguage, $availableLanguages, true)) {
            return $defaultLanguage;
        }

        return $this->languageCatalog->defaultLanguage();
    }

    public function homePath(): string
    {
        $homePath = $this->configValue(self::HOME_PATH_KEY, '/home');

        if (!is_string($homePath) || '' === trim($homePath)) {
            return '/home';
        }

        return ContentPathLookup::normalizePath($homePath);
    }

    /**
     * @param list<string> $preferredLanguages
     */
    public function resolve(string $path, array $preferredLanguages = []): LocalizedContentRoute
    {
        $path = ContentPathLookup::normalizePath($path);
        $defaultLanguage = $this->defaultLanguage();
        $availableLanguages = $this->availableLanguages();

        if (!$this->isEnabled()) {
            return LocalizedContentRoute::resolved($path, $defaultLanguage);
        }

        $segments = $this->segments($path);
        $prefix = $segments[0] ?? null;

        if (is_string($prefix) && in_array($prefix, $availableLanguages, true)) {
            array_shift($segments);

            return LocalizedContentRoute::resolved(
                [] === $segments ? '/' : '/'.implode('/', $segments),
                $prefix,
            );
        }

        $redirectLanguage = $this->preferredLanguage($preferredLanguages, $availableLanguages, $defaultLanguage);

        return LocalizedContentRoute::redirect(
            $path,
            $redirectLanguage,
            $this->prefixPath($redirectLanguage, $path),
        );
    }

    /**
     * @param list<string> $preferredLanguages
     * @param list<string> $availableLanguages
     */
    private function preferredLanguage(array $preferredLanguages, array $availableLanguages, string $defaultLanguage): string
    {
        foreach ($preferredLanguages as $preferredLanguage) {
            $normalized = strtolower(str_replace('_', '-', $preferredLanguage));

            foreach ($availableLanguages as $availableLanguage) {
                if ($normalized === strtolower(str_replace('_', '-', $availableLanguage))) {
                    return $availableLanguage;
                }
            }

            $primary = explode('-', $normalized)[0] ?? '';

            foreach ($availableLanguages as $availableLanguage) {
                if ($primary === strtolower(str_replace('_', '-', $availableLanguage))) {
                    return $availableLanguage;
                }
            }
        }

        return $defaultLanguage;
    }

    private function prefixPath(string $language, string $path): string
    {
        if ('/' === $path) {
            return '/'.$language;
        }

        return '/'.$language.$path;
    }

    /**
     * @return list<string>
     */
    private function segments(string $path): array
    {
        return array_values(array_filter(
            explode('/', trim($path, '/')),
            static fn (string $segment): bool => '' !== $segment,
        ));
    }

    private function configValue(string $key, mixed $default): mixed
    {
        return $this->config->get($key, $default);
    }
}
