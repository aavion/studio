<?php

declare(strict_types=1);

namespace App\Core\Translation;

use RuntimeException;

final class TranslationCatalogueCollisionException extends RuntimeException
{
    public function __construct(
        private readonly string $translationKey,
        private readonly string $source,
    ) {
        parent::__construct(sprintf('Translation key "%s" is defined more than once by "%s".', $translationKey, $source));
    }

    public function translationKey(): string
    {
        return $this->translationKey;
    }

    public function source(): string
    {
        return $this->source;
    }
}
