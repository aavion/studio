<?php

declare(strict_types=1);

namespace App\Localization;

use App\Core\Translation\TranslationRuntimePath;

final readonly class TranslationLanguageCatalog
{
    private TranslationRuntimePath $runtimePath;

    public function __construct(
        private string $projectDir,
        ?TranslationRuntimePath $runtimePath = null,
        private ?string $preferredDefaultLanguage = null,
    )
    {
        $this->runtimePath = $runtimePath ?? TranslationRuntimePath::fromGlobals($projectDir);
    }

    /**
     * @return list<string>
     */
    public function availableLanguages(): array
    {
        return (new LanguageCatalogueDiscovery($this->projectDir, $this->runtimePath))->availableLanguages();
    }

    public function defaultLanguage(): string
    {
        $languages = $this->availableLanguages();

        if (null !== $this->preferredDefaultLanguage && in_array($this->preferredDefaultLanguage, $languages, true)) {
            return $this->preferredDefaultLanguage;
        }

        return $languages[0] ?? LocaleToken::systemDefault();
    }
}
