<?php

declare(strict_types=1);

namespace App\Content\Api;

use App\Content\ContentStatus;
use App\Content\Read\PublishedContentResolver;
use App\Core\Access\AccessActor;
use Symfony\Component\HttpFoundation\Request;

final readonly class ContentApiItemListQuery
{
    public const MAX_PAGE = 1000;

    public function __construct(
        private PublishedContentResolver $contentResolver,
        private ContentApiPath $paths,
    ) {
    }

    /**
     * @return array{
     *     page: int,
     *     limit: int,
     *     status: string,
     *     statuses: list<ContentStatus>|null,
     *     schema: string,
     *     parent: string,
     *     parent_uid: string|null,
     *     sort: string,
     *     order_by: array<string, 'ASC'|'DESC'>
     * }
     */
    public function fromRequest(Request $request, AccessActor $actor): array
    {
        $status = $this->queryString($request, 'status', 'published');
        $parent = $this->queryString($request, 'parent', '');
        $sort = $this->queryString($request, 'sort', 'sort_order');

        return [
            'page' => $this->positiveIntQuery($request, 'page', 1, self::MAX_PAGE),
            'limit' => $this->positiveIntQuery($request, 'limit', 100, 100),
            'status' => $status,
            'statuses' => $this->statusFilter($status),
            'schema' => $this->queryString($request, 'schema', ''),
            'parent' => $parent,
            'parent_uid' => $this->parentUid($parent, $actor),
            'sort' => $sort,
            'order_by' => $this->orderBy($sort, $this->queryString($request, 'direction', 'asc')),
        ];
    }

    /**
     * @return list<ContentStatus>|null
     */
    private function statusFilter(string $status): ?array
    {
        return 'published' === $status ? [ContentStatus::Published] : null;
    }

    private function parentUid(string $parent, AccessActor $actor): ?string
    {
        if ('' === $parent) {
            return null;
        }

        $contentPath = $this->contentPathFromQuery($parent);
        $view = $this->contentResolver->resolveByPath($contentPath, $actor)->view();

        return $view?->content()->uid() ?? '__api_parent_not_found__';
    }

    private function contentPathFromQuery(string $parent): string
    {
        if (str_starts_with($parent, ContentApiPath::BASE.'/')) {
            $path = $this->paths->itemFromRequestPath($parent);

            return $path?->contentPath() ?? '/__api_parent_not_found__';
        }

        return '/'.str_replace('/items/', '/', trim($parent, '/'));
    }

    /**
     * @return array<string, 'ASC'|'DESC'>
     */
    private function orderBy(string $sort, string $direction): array
    {
        $descending = str_starts_with($sort, '-') || 'desc' === strtolower($direction);
        $sort = ltrim($sort, '-');
        $direction = $descending ? 'DESC' : 'ASC';

        return match ($sort) {
            'slug' => ['item.slug' => $direction, 'item.sortOrder' => 'ASC'],
            'status' => ['item.status' => $direction, 'item.sortOrder' => 'ASC', 'item.slug' => 'ASC'],
            'schema' => ['schema.identifier' => $direction, 'item.sortOrder' => 'ASC', 'item.slug' => 'ASC'],
            default => ['item.sortOrder' => $direction, 'item.slug' => 'ASC'],
        };
    }

    private function positiveIntQuery(Request $request, string $key, int $default, int $max): int
    {
        $value = $request->query->get($key);
        $number = is_numeric($value) ? (int) $value : $default;

        return max(1, min($max, $number));
    }

    private function queryString(Request $request, string $key, string $default): string
    {
        $value = $request->query->get($key);

        return is_string($value) ? $value : $default;
    }
}
