<?php

declare(strict_types=1);

namespace App\Content\Api;

use App\Content\Routing\ContentSlug;
use App\Entity\ContentItem;
use App\Repository\ContentItemRepository;

final readonly class ContentApiPath
{
    public const BASE = '/api/v1/content/items';
    public const CHILD_SEPARATOR = '/items/';

    public function __construct(private ContentItemRepository $contentItems)
    {
    }

    public function itemFromRequestPath(string $path): ?ContentApiItemPath
    {
        $tail = $this->tail($path);
        if (null === $tail || '' === $tail) {
            return null;
        }

        $variant = null;
        if (str_contains($tail, '/variants/')) {
            [$tail, $variant] = explode('/variants/', $tail, 2);
            if (!$this->validSlug($variant)) {
                return null;
            }
        }

        $segments = explode(self::CHILD_SEPARATOR, $tail);
        if (!$this->validSegments($segments)) {
            return null;
        }

        return new ContentApiItemPath('/'.implode('/', $segments), $variant);
    }

    public function parentFromCollectionPath(string $path, string $collection): ?string
    {
        $suffix = '/'.$collection;
        if (!str_ends_with($path, $suffix)) {
            return null;
        }

        $item = $this->itemFromRequestPath(substr($path, 0, -strlen($suffix)));

        return $item?->contentPath();
    }

    public function itemPath(ContentItem $item): string
    {
        $segments = [$item->slug()];
        $parentUid = $item->parentUid();
        $seen = [$item->uid() => true];

        while (null !== ($parent = $this->contentItems->find($parentUid))) {
            if (isset($seen[$parent->uid()])) {
                break;
            }

            array_unshift($segments, $parent->slug());
            $seen[$parent->uid()] = true;
            $parentUid = $parent->parentUid();
        }

        return self::BASE.'/'.implode(self::CHILD_SEPARATOR, $segments);
    }

    public function variantPath(ContentItem $item, string $variant): string
    {
        return $this->itemPath($item).'/variants/'.$variant;
    }

    /**
     * @param list<string> $segments
     */
    private function validSegments(array $segments): bool
    {
        if ([] === $segments) {
            return false;
        }

        foreach ($segments as $segment) {
            if (!$this->validSlug($segment)) {
                return false;
            }
        }

        return true;
    }

    private function validSlug(string $slug): bool
    {
        return ContentSlug::isValid($slug);
    }

    private function tail(string $path): ?string
    {
        if (!str_starts_with($path, self::BASE.'/')) {
            return null;
        }

        return substr($path, strlen(self::BASE) + 1);
    }

}
