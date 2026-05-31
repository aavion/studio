<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\Translation\TranslationRuntimePath;

final readonly class SetupLanguageCatalog
{
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

        if (in_array('en', $languages, true)) {
            return 'en';
        }

        return $languages[0] ?? 'en';
    }

    public function supports(string $projectDir, string $language, ?string $environment = null): bool
    {
        return in_array($language, $this->availableLanguages($projectDir, $environment), true);
    }
}
