<?php

declare(strict_types=1);

namespace App\Navigation;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Throwable;

final readonly class NavigationUrlResolver
{
    public function __construct(private UrlGeneratorInterface $urlGenerator)
    {
    }

    /**
     * @param list<NavigationItem> $items
     *
     * @return list<NavigationItem>
     */
    public function resolve(array $items): array
    {
        return array_map(fn (NavigationItem $item): NavigationItem => $this->resolveItem($item), $items);
    }

    private function resolveItem(NavigationItem $item): NavigationItem
    {
        if ('route' !== $item->targetType()) {
            return $item->withResolvedUrl(match ($item->targetType()) {
                'url', 'content' => $this->safeNavigationUrl($item->targetValue()),
                default => '#',
            });
        }

        $parameters = $item->metadata()['route_parameters'] ?? [];

        if (!is_array($parameters)) {
            $parameters = [];
        }

        try {
            return $item->withResolvedUrl($this->urlGenerator->generate($item->targetValue(), $parameters));
        } catch (Throwable) {
            return $item->withResolvedUrl('#');
        }
    }

    private function safeNavigationUrl(string $targetValue): string
    {
        $targetValue = trim($targetValue);

        if ('' === $targetValue || 1 === preg_match('/[\x00-\x1F\x7F]/', $targetValue)) {
            return '#';
        }

        if (str_starts_with($targetValue, '//') || str_starts_with($targetValue, '/\\')) {
            return '#';
        }

        $scheme = parse_url($targetValue, PHP_URL_SCHEME);

        if (null === $scheme) {
            return $targetValue;
        }

        if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true)) {
            return '#';
        }

        $host = parse_url($targetValue, PHP_URL_HOST);

        return is_string($host) && '' !== trim($host) ? $targetValue : '#';
    }
}
