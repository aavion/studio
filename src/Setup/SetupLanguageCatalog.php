<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\Translation\TranslationRuntimePath;
use App\Localization\LocaleToken;
use Symfony\Component\Yaml\Yaml;

final class SetupLanguageCatalog
{
    /** @var array<string, string|null> */
    private array $configuredDefaultLanguages = [];

    /**
     * @return list<string>
     */
    public function availableLanguages(string $projectDir, ?string $environment = null): array
    {
        $languages = [];
        $runtimePath = new TranslationRuntimePath($projectDir, $environment ?? (string) ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'dev'));

        foreach ($runtimePath->generatedCataloguePaths() as $path) {
            if (1 === preg_match('/messages\.([a-z][a-z0-9]*(?:[_-][a-zA-Z0-9]+)*)\.yaml$/', basename($path), $matches)) {
                $languages[] = $matches[1];
            }
        }

        foreach (glob($projectDir.'/translations/languages/*', GLOB_ONLYDIR) ?: [] as $path) {
            if (1 === preg_match('/^[a-z][a-z0-9]*(?:[_-][a-zA-Z0-9]+)*$/', basename($path))) {
                $languages[] = basename($path);
            }
        }

        $languages = array_values(array_unique($languages));
        sort($languages);

        return $languages;
    }

    public function defaultLanguage(string $projectDir, ?string $environment = null): string
    {
        $languages = $this->availableLanguages($projectDir, $environment);
        $configuredDefaultLanguage = $this->configuredDefaultLanguage($projectDir);

        if (is_string($configuredDefaultLanguage) && in_array($configuredDefaultLanguage, $languages, true)) {
            return $configuredDefaultLanguage;
        }

        return $languages[0] ?? $configuredDefaultLanguage ?? LocaleToken::systemDefault();
    }

    public function supports(string $projectDir, string $language, ?string $environment = null): bool
    {
        return in_array($language, $this->availableLanguages($projectDir, $environment), true);
    }

    private function configuredDefaultLanguage(string $projectDir): ?string
    {
        if (array_key_exists($projectDir, $this->configuredDefaultLanguages)) {
            return $this->configuredDefaultLanguages[$projectDir];
        }

        $path = $projectDir.'/config/packages/translation.yaml';

        if (!is_file($path)) {
            return $this->configuredDefaultLanguages[$projectDir] = null;
        }

        $data = Yaml::parseFile($path);
        $locale = is_array($data) ? ($data['framework']['default_locale'] ?? null) : null;

        if (!is_string($locale) || !LocaleToken::isValid($locale)) {
            return $this->configuredDefaultLanguages[$projectDir] = null;
        }

        return $this->configuredDefaultLanguages[$projectDir] = $locale;
    }
}
