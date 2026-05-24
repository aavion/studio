<?php

declare(strict_types=1);

namespace App\Content\Routing;

final readonly class ContentRedirectTarget
{
    private function __construct(
        private string $value,
        private ContentRedirectTargetType $type,
    ) {
    }

    public static function internalRoute(string $route): self
    {
        return new self($route, ContentRedirectTargetType::InternalRoute);
    }

    public static function externalUrl(string $url): self
    {
        return new self($url, ContentRedirectTargetType::ExternalUrl);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function type(): ContentRedirectTargetType
    {
        return $this->type;
    }

    public function isInternalRoute(): bool
    {
        return ContentRedirectTargetType::InternalRoute === $this->type;
    }

    public function isExternalUrl(): bool
    {
        return ContentRedirectTargetType::ExternalUrl === $this->type;
    }
}
