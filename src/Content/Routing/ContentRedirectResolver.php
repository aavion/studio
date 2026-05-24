<?php

declare(strict_types=1);

namespace App\Content\Routing;

final readonly class ContentRedirectResolver
{
    public const DEFAULT_MAX_HOPS = 5;

    public function __construct(
        private ContentPathLookup $pathLookup,
    ) {
    }

    public function resolveByPath(string $path, int $maxHops = self::DEFAULT_MAX_HOPS): ContentRedirectResolveResult
    {
        $requestedRoute = ContentPathLookup::normalizePath($path);
        $source = $this->pathLookup->findByPath($requestedRoute);

        if (null === $source) {
            return ContentRedirectResolveResult::notFound($requestedRoute);
        }

        $target = $this->normalizeRedirectTarget($source->redirectTarget());

        if (null === $target) {
            return ContentRedirectResolveResult::invalidTarget($requestedRoute, $source, $source->redirectTarget(), [$requestedRoute]);
        }

        if ('' === $target) {
            return ContentRedirectResolveResult::noRedirect($requestedRoute, $source);
        }

        if ($target->isExternalUrl()) {
            return ContentRedirectResolveResult::resolved($requestedRoute, $source, $target, [$requestedRoute, $target->value()]);
        }

        $visited = [$requestedRoute => true];
        $routeChain = [$requestedRoute];
        $currentRoute = $target->value();

        for ($hop = 0; $hop < $maxHops; ++$hop) {
            if (isset($visited[$currentRoute])) {
                $routeChain[] = $currentRoute;

                return ContentRedirectResolveResult::loopDetected($requestedRoute, $source, $currentRoute, $routeChain);
            }

            $visited[$currentRoute] = true;
            $routeChain[] = $currentRoute;
            $targetContent = $this->pathLookup->findByPath($currentRoute);

            if (null === $targetContent) {
                return ContentRedirectResolveResult::resolved(
                    $requestedRoute,
                    $source,
                    ContentRedirectTarget::internalRoute($currentRoute),
                    $routeChain,
                );
            }

            $nextTarget = $this->normalizeRedirectTarget($targetContent->redirectTarget());

            if (null === $nextTarget) {
                return ContentRedirectResolveResult::invalidTarget($requestedRoute, $source, $targetContent->redirectTarget(), $routeChain);
            }

            if ('' === $nextTarget) {
                return ContentRedirectResolveResult::resolved(
                    $requestedRoute,
                    $source,
                    ContentRedirectTarget::internalRoute($currentRoute),
                    $routeChain,
                );
            }

            if ($nextTarget->isExternalUrl()) {
                $routeChain[] = $nextTarget->value();

                return ContentRedirectResolveResult::resolved($requestedRoute, $source, $nextTarget, $routeChain);
            }

            $currentRoute = $nextTarget->value();
        }

        return ContentRedirectResolveResult::hopLimitExceeded($requestedRoute, $source, $currentRoute, $routeChain);
    }

    private function normalizeRedirectTarget(?string $redirectRoute): ContentRedirectTarget|string|null
    {
        if (null === $redirectRoute || '' === trim($redirectRoute)) {
            return '';
        }

        $redirectRoute = trim($redirectRoute);

        if (1 === preg_match('/^https?:\/\//i', $redirectRoute)) {
            if (str_contains($redirectRoute, "\0") || str_contains($redirectRoute, '\\')) {
                return null;
            }

            return ContentRedirectTarget::externalUrl($redirectRoute);
        }

        if (
            str_contains($redirectRoute, "\0")
            || str_contains($redirectRoute, '\\')
            || str_contains($redirectRoute, '?')
            || str_contains($redirectRoute, '#')
            || str_starts_with($redirectRoute, '//')
            || 1 === preg_match('/^[a-z][a-z0-9+.-]*:/i', $redirectRoute)
        ) {
            return null;
        }

        return ContentRedirectTarget::internalRoute(ContentPathLookup::normalizePath($redirectRoute));
    }
}
