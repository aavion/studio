<?php

declare(strict_types=1);

namespace App\Localization;

final readonly class TranslationLanguageCatalog
{
    public function __construct(private string $projectDir)
    {
    }

    /**
     * @return list<string>
     */
    public function availableLanguages(): array
    {
        $languages = [];

        foreach (glob($this->projectDir.'/translations/messages.*.yaml') ?: [] as $path) {
            if (1 === preg_match('/messages\.([a-z][a-z0-9]*(?:[_-][a-zA-Z0-9]+)*)\.yaml$/', basename($path), $matches)) {
                $languages[] = $matches[1];
            }
        }

        $languages = array_values(array_unique($languages));
        sort($languages);

        return $languages;
    }

    public function defaultLanguage(): string
    {
        $languages = $this->availableLanguages();

        if (in_array('en', $languages, true)) {
            return 'en';
        }

        return $languages[0] ?? 'en';
    }
}
