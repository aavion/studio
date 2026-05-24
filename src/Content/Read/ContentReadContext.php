<?php

declare(strict_types=1);

namespace App\Content\Read;

final readonly class ContentReadContext
{
    public function __construct(
        private string $requestedLanguage,
        private string $language,
        private string $requestedVariant,
        private string $variant,
    ) {
    }

    public function requestedLanguage(): string
    {
        return $this->requestedLanguage;
    }

    public function language(): string
    {
        return $this->language;
    }

    public function languageFallbackUsed(): bool
    {
        return $this->requestedLanguage !== $this->language;
    }

    public function requestedVariant(): string
    {
        return $this->requestedVariant;
    }

    public function variant(): string
    {
        return $this->variant;
    }

    public function variantFallbackUsed(): bool
    {
        return $this->requestedVariant !== $this->variant;
    }
}
