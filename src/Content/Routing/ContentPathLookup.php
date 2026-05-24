<?php

declare(strict_types=1);

namespace App\Content\Routing;

use App\Entity\ContentItem;
use App\Repository\ContentItemRepository;

final readonly class ContentPathLookup
{
    public function __construct(
        private ContentItemRepository $contentItems,
    ) {
    }

    public function findByPath(string $path): ?ContentItem
    {
        $path = ContentRoutePath::fromPath($path)->path();
        $content = $this->contentItems->findOneContentByCustomUrl($path);

        if (null !== $content) {
            return $content;
        }

        return $this->findByHierarchyPath($path);
    }

    public static function normalizePath(string $path): string
    {
        if ('/' === $path) {
            return '/';
        }

        return '/'.trim($path, '/');
    }

    private function findByHierarchyPath(string $path): ?ContentItem
    {
        $segments = $this->pathSegments($path);
        $parentUid = null;
        $content = null;

        if (ContentSystemRoute::PREFIX === ($segments[0] ?? null)) {
            array_shift($segments);
            $parentUid = ContentSystemRoute::VIRTUAL_PARENT_UID;
        }

        if ([] === $segments) {
            return null;
        }

        foreach ($segments as $segment) {
            $content = $this->contentItems->findOneContentBySlugAndParentUid($segment, $parentUid);

            if (null === $content) {
                return null;
            }

            $parentUid = $content->uid();
        }

        return $content;
    }

    /**
     * @return list<string>
     */
    private function pathSegments(string $path): array
    {
        return array_values(array_filter(
            explode('/', trim($path, '/')),
            static fn (string $segment): bool => '' !== $segment,
        ));
    }
}
