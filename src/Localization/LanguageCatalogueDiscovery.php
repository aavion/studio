<?php

declare(strict_types=1);

namespace App\Localization;

use App\Core\Translation\TranslationRuntimePath;

final readonly class LanguageCatalogueDiscovery
{
    private const LOCALE_PATTERN = '[a-z][a-z0-9]*(?:[_-][a-zA-Z0-9]+)*';

    public function __construct(
        private string $projectDir,
        private TranslationRuntimePath $runtimePath,
    ) {
    }

    public static function fromEnvironment(string $projectDir, ?string $environment = null): self
    {
        return new self(
            $projectDir,
            new TranslationRuntimePath(
                $projectDir,
                $environment ?? (string) ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'dev'),
            ),
        );
    }

    /**
     * @return list<string>
     */
    public function availableLanguages(): array
    {
        $languages = [];

        foreach ($this->runtimePath->generatedCataloguePaths() as $path) {
            if (1 === preg_match('/messages\.('.self::LOCALE_PATTERN.')\.yaml$/', basename($path), $matches)) {
                $languages[] = $matches[1];
            }
        }

        foreach (glob($this->projectDir.'/translations/languages/*', GLOB_ONLYDIR) ?: [] as $path) {
            if (1 === preg_match('/^'.self::LOCALE_PATTERN.'$/', basename($path))) {
                $languages[] = basename($path);
            }
        }

        $languages = array_values(array_unique($languages));
        sort($languages);

        return $languages;
    }
}
