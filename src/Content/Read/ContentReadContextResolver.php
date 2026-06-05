<?php

declare(strict_types=1);

namespace App\Content\Read;

use App\Entity\ContentItem;

final readonly class ContentReadContextResolver
{
    public function __construct(
        private string $defaultLanguage = '',
        private string $defaultVariant = 'default',
    ) {
    }

    public function resolve(ContentItem $content, string $language = '', string $variant = 'default'): ?ContentReadContext
    {
        $language = trim($language);
        $variant = trim($variant);
        $language = '' === $language ? $this->defaultLanguage : $language;
        $variant = '' === $variant ? $this->defaultVariant : $variant;
        $resolvedLanguage = $this->resolveLanguage($content->availableLanguages(), $language);
        $resolvedVariant = $this->resolveVariant($content->availableVariants(), $variant);

        if (null === $resolvedLanguage || null === $resolvedVariant) {
            return null;
        }

        return new ContentReadContext($language, $resolvedLanguage, $variant, $resolvedVariant);
    }

    /**
     * @param list<string> $availableLanguages
     */
    private function resolveLanguage(array $availableLanguages, string $requestedLanguage): ?string
    {
        if (in_array($requestedLanguage, $availableLanguages, true)) {
            return $requestedLanguage;
        }

        if ('' !== $this->defaultLanguage && in_array($this->defaultLanguage, $availableLanguages, true)) {
            return $this->defaultLanguage;
        }

        return $availableLanguages[0] ?? null;
    }

    /**
     * @param list<string> $availableVariants
     */
    private function resolveVariant(array $availableVariants, string $requestedVariant): ?string
    {
        if (in_array($requestedVariant, $availableVariants, true)) {
            return $requestedVariant;
        }

        if (in_array($this->defaultVariant, $availableVariants, true)) {
            return $this->defaultVariant;
        }

        return $availableVariants[0] ?? null;
    }
}
