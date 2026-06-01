<?php

declare(strict_types=1);

namespace App\Content\Routing;

use App\Core\Message\MessageException;
use App\Core\Message\MessageKey;

final readonly class ContentRouteGuard
{
    /**
     * @var list<string>
     */
    public const DEFAULT_RESERVED_PREFIXES = [
        'admin',
        'editor',
        'setup',
        'cron',
        ContentSystemRoute::PREFIX,
        'user',
        'api',
        'assets',
        '_profiler',
        '_wdt',
        'build',
        'packages',
        'media',
        'files',
    ];

    /**
     * @var list<string>
     */
    private array $reservedPrefixes;

    /**
     * @param list<string> $reservedPrefixes
     */
    public function __construct(
        array $reservedPrefixes = self::DEFAULT_RESERVED_PREFIXES,
        private ?ContentRouteLocalization $localization = null,
    ) {
        $this->reservedPrefixes = array_values(array_unique($reservedPrefixes));
    }

    public function assertSlugAllowed(string|ContentSlug $slug): ContentSlug
    {
        $slug = $slug instanceof ContentSlug ? $slug : ContentSlug::fromString($slug);

        if (in_array($slug->value(), $this->reservedPrefixes(), true)) {
            throw MessageException::invalidArgument(MessageKey::CONTENT_SLUG_RESERVED, [
                '%slug%' => $slug->value(),
            ]);
        }

        return $slug;
    }

    public function assertPathAllowed(string $path): string
    {
        $path = $this->normalizePath($path);
        $segments = explode('/', ltrim($path, '/'));
        $firstSegment = $segments[0] ?? '';

        if (in_array($firstSegment, $this->reservedPrefixes(), true)) {
            throw MessageException::invalidArgument(MessageKey::CONTENT_PATH_RESERVED_PREFIX, [
                '%path%' => $path,
                '%prefix%' => $firstSegment,
            ]);
        }

        foreach ($segments as $index => $segment) {
            $this->assertPathSegment($segment, $path, $index === array_key_last($segments));
        }

        return $path;
    }

    /**
     * @return list<string>
     */
    private function reservedPrefixes(): array
    {
        $prefixes = $this->reservedPrefixes;

        if (true === $this->localization?->isEnabled()) {
            array_push($prefixes, ...$this->localization->availableLanguages());
        }

        return array_values(array_unique($prefixes));
    }

    private function normalizePath(string $path): string
    {
        if ('' === $path || trim($path) !== $path) {
            throw MessageException::invalidArgument(MessageKey::CONTENT_PATH_EMPTY_OR_PADDED, [
                '%path%' => $path,
            ]);
        }

        if (str_contains($path, "\0") || str_contains($path, '\\') || str_contains($path, '?') || str_contains($path, '#')) {
            throw MessageException::invalidArgument(MessageKey::CONTENT_PATH_UNCLEAN, [
                '%path%' => $path,
            ]);
        }

        $path = '/' . trim($path, '/');

        if ('/' === $path || str_contains($path, '//')) {
            throw MessageException::invalidArgument(MessageKey::CONTENT_PATH_EMPTY_SEGMENT, [
                '%path%' => $path,
            ]);
        }

        return $path;
    }

    private function assertPathSegment(string $segment, string $path, bool $isLastSegment): void
    {
        if ('.' === $segment || '..' === $segment) {
            throw MessageException::invalidArgument(MessageKey::CONTENT_PATH_TRAVERSAL, [
                '%path%' => $path,
                '%segment%' => $segment,
            ]);
        }

        if (str_starts_with($segment, '~')) {
            $variant = substr($segment, 1);

            if (!$isLastSegment || !ContentSlug::isValid($variant)) {
                throw MessageException::invalidArgument(MessageKey::CONTENT_PATH_VARIANT_INVALID, [
                    '%path%' => $path,
                    '%variant%' => $variant,
                ]);
            }

            return;
        }

        ContentSlug::fromString($segment);
    }
}
