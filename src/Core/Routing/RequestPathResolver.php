<?php

declare(strict_types=1);

namespace App\Core\Routing;

use App\Content\Routing\ContentRouteLocalization;
use Symfony\Component\HttpFoundation\Request;

final readonly class RequestPathResolver
{
    /**
     * @var list<string>
     */
    private const LOCALE_PREFIX_SCOPED_SEGMENTS = ['admin', 'editor', 'user', 'users'];

    public function __construct(private ?ContentRouteLocalization $routeLocalization = null)
    {
    }

    /**
     * @return list<string>
     */
    public function segments(Request $request): array
    {
        $segments = $this->segmentsFromPath($request->getPathInfo());
        $locale = $this->localePrefix($request, $segments);

        if (is_string($locale) && ($segments[0] ?? null) === $locale) {
            array_shift($segments);
        }

        return $segments;
    }

    public function matches(Request $request, string ...$segments): bool
    {
        $pathSegments = $this->segments($request);
        foreach ($segments as $index => $segment) {
            if (($pathSegments[$index] ?? null) !== trim($segment, '/')) {
                return false;
            }
        }

        return [] !== $segments;
    }

    public function matchesExact(Request $request, string ...$segments): bool
    {
        return count($this->segments($request)) === count($segments) && $this->matches($request, ...$segments);
    }

    /**
     * @param list<string> ...$scopes
     */
    public function matchesAny(Request $request, array ...$scopes): bool
    {
        foreach ($scopes as $scope) {
            if ($this->matches($request, ...$scope)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function segmentsFromPath(string $path): array
    {
        return array_values(array_filter(
            explode('/', trim($path, '/')),
            static fn (string $segment): bool => '' !== $segment,
        ));
    }

    /**
     * @param list<string> $segments
     */
    private function localePrefix(Request $request, array $segments): ?string
    {
        $firstSegment = $segments[0] ?? '';

        if ('' === $firstSegment || !in_array($segments[1] ?? '', self::LOCALE_PREFIX_SCOPED_SEGMENTS, true)) {
            return null;
        }

        $locale = $request->attributes->get('_locale');
        if (is_string($locale) && $firstSegment === $locale) {
            return $firstSegment;
        }

        if (null !== $this->routeLocalization && $this->routeLocalization->isEnabled() && in_array($firstSegment, $this->routeLocalization->availableLanguages(), true)) {
            return $firstSegment;
        }

        return null;
    }
}
