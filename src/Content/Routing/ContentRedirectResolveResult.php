<?php

declare(strict_types=1);

namespace App\Content\Routing;

use App\Entity\ContentItem;

final readonly class ContentRedirectResolveResult
{
    /**
     * @param list<string> $routeChain
     */
    private function __construct(
        private ContentRedirectResolveStatus $status,
        private string $requestedRoute,
        private ?ContentItem $source = null,
        private ?ContentRedirectTarget $target = null,
        private ?string $invalidTarget = null,
        private array $routeChain = [],
    ) {
    }

    public static function notFound(string $requestedRoute): self
    {
        return new self(ContentRedirectResolveStatus::NotFound, $requestedRoute);
    }

    public static function noRedirect(string $requestedRoute, ContentItem $source): self
    {
        return new self(ContentRedirectResolveStatus::NoRedirect, $requestedRoute, $source);
    }

    /**
     * @param list<string> $routeChain
     */
    public static function resolved(string $requestedRoute, ContentItem $source, ContentRedirectTarget $target, array $routeChain): self
    {
        return new self(ContentRedirectResolveStatus::Resolved, $requestedRoute, $source, $target, null, $routeChain);
    }

    /**
     * @param list<string> $routeChain
     */
    public static function invalidTarget(string $requestedRoute, ContentItem $source, ?string $redirectRoute, array $routeChain): self
    {
        return new self(
            ContentRedirectResolveStatus::InvalidTarget,
            $requestedRoute,
            $source,
            null,
            $redirectRoute,
            $routeChain,
        );
    }

    /**
     * @param list<string> $routeChain
     */
    public static function loopDetected(string $requestedRoute, ContentItem $source, string $redirectRoute, array $routeChain): self
    {
        return new self(
            ContentRedirectResolveStatus::LoopDetected,
            $requestedRoute,
            $source,
            ContentRedirectTarget::internalRoute($redirectRoute),
            null,
            $routeChain,
        );
    }

    /**
     * @param list<string> $routeChain
     */
    public static function hopLimitExceeded(string $requestedRoute, ContentItem $source, ?string $redirectRoute, array $routeChain): self
    {
        return new self(
            ContentRedirectResolveStatus::HopLimitExceeded,
            $requestedRoute,
            $source,
            null === $redirectRoute ? null : ContentRedirectTarget::internalRoute($redirectRoute),
            null,
            $routeChain,
        );
    }

    public function status(): ContentRedirectResolveStatus
    {
        return $this->status;
    }

    public function isResolved(): bool
    {
        return ContentRedirectResolveStatus::Resolved === $this->status;
    }

    public function requestedRoute(): string
    {
        return $this->requestedRoute;
    }

    public function source(): ?ContentItem
    {
        return $this->source;
    }

    public function redirectRoute(): ?string
    {
        return $this->target?->value() ?? $this->invalidTarget;
    }

    public function target(): ?ContentRedirectTarget
    {
        return $this->target;
    }

    public function targetType(): ?ContentRedirectTargetType
    {
        return $this->target?->type();
    }

    public function isInternalRoute(): bool
    {
        return true === $this->target?->isInternalRoute();
    }

    public function isExternalUrl(): bool
    {
        return true === $this->target?->isExternalUrl();
    }

    /**
     * @return list<string>
     */
    public function routeChain(): array
    {
        return $this->routeChain;
    }
}
