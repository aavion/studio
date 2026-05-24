<?php

declare(strict_types=1);

namespace App\Content\Read;

use App\Entity\ContentItem;

final readonly class ContentReadContextResolver
{
    public function __construct(
        private string $defaultLanguage = 'en',
        private string $defaultVariant = 'default',
    ) {
    }

    public function resolve(ContentItem $content, string $language = 'en', string $variant = 'default'): ?ContentReadContext
    {
        $language = trim($language);
        $variant = trim($variant);
        $language = '' === $language ? $this->defaultLanguage : $language;
        $variant = '' === $variant ? $this->defaultVariant : $variant;
        $resolvedLanguage = $this->resolveLanguage($content->availableLanguages(), $language);

        if (null === $resolvedLanguage || !in_array($variant, $content->availableVariants(), true)) {
            return null;
        }

        return new ContentReadContext($language, $resolvedLanguage, $variant, $variant);
    }

    /**
     * @param list<string> $availableLanguages
     */
    private function resolveLanguage(array $availableLanguages, string $requestedLanguage): ?string
    {
        if (in_array($requestedLanguage, $availableLanguages, true)) {
            return $requestedLanguage;
        }

        if (in_array($this->defaultLanguage, $availableLanguages, true)) {
            return $this->defaultLanguage;
        }

        return $availableLanguages[0] ?? null;
    }
}
