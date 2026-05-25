<?php

declare(strict_types=1);

namespace App\Setup;

final readonly class SetupLanguageCatalog
{
    /**
     * @return list<string>
     */
    public function availableLanguages(string $projectDir): array
    {
        $languages = [];

        foreach (glob($projectDir.'/translations/messages.*.yaml') ?: [] as $path) {
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

    public function defaultLanguage(string $projectDir): string
    {
        $languages = $this->availableLanguages($projectDir);

        if (in_array('en', $languages, true)) {
            return 'en';
        }

        return $languages[0] ?? 'en';
    }

    public function supports(string $projectDir, string $language): bool
    {
        return in_array($language, $this->availableLanguages($projectDir), true);
    }
}
