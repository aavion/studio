<?php

declare(strict_types=1);

namespace App\Content\Routing;

final readonly class LocalizedContentRoute
{
    private function __construct(
        private string $contentPath,
        private string $language,
        private ?string $redirectPath = null,
    ) {
    }

    public static function resolved(string $contentPath, string $language): self
    {
        return new self($contentPath, $language);
    }

    public static function redirect(string $contentPath, string $language, string $redirectPath): self
    {
        return new self($contentPath, $language, $redirectPath);
    }

    public function contentPath(): string
    {
        return $this->contentPath;
    }

    public function language(): string
    {
        return $this->language;
    }

    public function redirectPath(): ?string
    {
        return $this->redirectPath;
    }

    public function shouldRedirect(): bool
    {
        return null !== $this->redirectPath;
    }
}
