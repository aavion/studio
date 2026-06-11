<?php

declare(strict_types=1);

namespace App\Content\Api;

use App\Content\ContentStatus;
use App\Content\ContentVisibility;
use App\Content\Read\ContentReadAccessPolicy;
use App\Content\Read\PublishedContentResolver;
use App\Content\Read\PublishedContentView;
use App\Core\Access\AccessActor;
use App\Entity\ContentItem;
use App\Entity\ContentRevision;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

final readonly class ContentApiItemReadModel
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ContentReadAccessPolicy $accessPolicy,
        private PublishedContentResolver $contentResolver,
        private ContentApiPath $paths,
        private ContentApiItemListQuery $listQuery,
        private ContentApiVisibleItemPager $visibleItemPager,
    ) {
    }

    /**
     * @return array{
     *     items: list<array<string, mixed>>,
     *     pagination: array{page: int, limit: int, returned: int},
     *     filters: array{status: string, schema: string, parent: string, sort: string}
     * }
     */
    public function visibleItems(Request $request, AccessActor $actor): array
    {
        $query = $this->listQuery->fromRequest($request, $actor);
        if (null === $query['statuses']) {
            return $this->emptyList($query);
        }

        $queryBuilder = $this->entityManager->getRepository(ContentItem::class)->createQueryBuilder('item')
            ->andWhere('item.visibility = :visibility')
            ->setParameter('visibility', ContentVisibility::Public);

        if ([] !== $query['statuses']) {
            $queryBuilder
                ->andWhere('item.status IN (:statuses)')
                ->setParameter('statuses', $query['statuses']);
        }

        if ('' !== $query['schema'] || 'schema' === ltrim($query['sort'], '-')) {
            $queryBuilder->innerJoin('item.schema', 'schema');
        }

        if ('' !== $query['schema']) {
            $queryBuilder
                ->andWhere('schema.identifier = :schema')
                ->setParameter('schema', $query['schema']);
        }

        if (null !== $query['parent_uid']) {
            $queryBuilder
                ->andWhere('item.parentUid = :parentUid')
                ->setParameter('parentUid', $query['parent_uid']);
        }

        foreach ($query['order_by'] as $field => $direction) {
            $queryBuilder->addOrderBy($field, $direction);
        }

        $items = $this->visibleItemPager->page($queryBuilder, $query['page'], $query['limit'], $actor);
        $visibleItems = array_map(fn (ContentItem $item): array => $this->itemResource($item), $items);

        return [
            'items' => $visibleItems,
            'pagination' => [
                'page' => $query['page'],
                'limit' => $query['limit'],
                'returned' => count($visibleItems),
            ],
            'filters' => [
                'status' => $query['status'],
                'schema' => $query['schema'],
                'parent' => $query['parent'],
                'sort' => $query['sort'],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function visibleChildren(string $parentPath, AccessActor $actor): array
    {
        $parent = $this->contentResolver->resolveByPath($parentPath, $actor)->view()?->content();
        if (!$parent instanceof ContentItem) {
            return [];
        }

        $children = $this->entityManager->getRepository(ContentItem::class)->findBy(
            ['parentUid' => $parent->uid(), 'status' => ContentStatus::Published, 'visibility' => ContentVisibility::Public],
            ['sortOrder' => 'ASC', 'slug' => 'ASC'],
            100,
        );

        return array_values(array_filter(array_map(
            fn (ContentItem $item): ?array => $this->accessPolicy->allowsView($item, $actor) ? $this->itemResource($item) : null,
            $children,
        )));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function variants(string $contentPath, AccessActor $actor): array
    {
        $view = $this->contentResolver->resolveByPath($contentPath, $actor)->view();
        if (!$view instanceof PublishedContentView) {
            return [];
        }

        return array_map(
            fn (string $variant): array => [
                'type' => 'content_variant',
                'id' => $variant,
                'attributes' => ['variant' => $variant],
                'links' => ['self' => $this->paths->variantPath($view->content(), $variant)],
            ],
            $view->content()->availableVariants(),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function versions(string $contentPath, AccessActor $actor): array
    {
        $view = $this->contentResolver->resolveByPath($contentPath, $actor)->view();
        if (!$view instanceof PublishedContentView) {
            return [];
        }

        return array_values(array_map(
            fn (ContentRevision $revision): array => $this->versionResource(
                $revision,
                $view->content()->activeRevisionUid() === $revision->uid(),
            ),
            $view->content()->revisions()->toArray(),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function viewResource(PublishedContentView $view): array
    {
        $item = $view->content();
        $basePath = $this->paths->itemPath($item);
        $self = $basePath;
        $variant = $view->context()->variant();
        if ('default' !== $variant) {
            $self = $this->paths->variantPath($item, $variant);
        }

        $resource = $this->itemResource($item, $self);

        return [
            ...$resource,
            'attributes' => [
                ...$resource['attributes'],
                'language' => $view->context()->language(),
                'requested_language' => $view->context()->requestedLanguage(),
                'variant' => $variant,
                'requested_variant' => $view->context()->requestedVariant(),
                'fields' => $view->fields(),
            ],
            'links' => [
                ...$resource['links'],
                'children' => $basePath.'/items',
                'variants' => $basePath.'/variants',
                'revisions' => $basePath.'/revisions',
            ],
        ];
    }

    private function itemResource(ContentItem $item, ?string $self = null): array
    {
        $self ??= $this->paths->itemPath($item);

        return [
            'type' => 'content_item',
            'id' => substr($self, strlen(ContentApiPath::BASE) + 1),
            'attributes' => [
                'path' => $self,
                'slug' => $item->slug(),
                'status' => $item->status()->value,
                'visibility' => $item->visibility()->value,
                'custom_url' => $item->customUrl(),
                'languages' => $item->availableLanguages(),
                'variants' => $item->availableVariants(),
                'schema' => $item->schema()?->identifier(),
                'schema_version' => $item->activeRevision()?->schemaVersion()->version(),
            ],
            'links' => [
                'self' => $self,
                'children' => $self.'/items',
                'variants' => $self.'/variants',
                'revisions' => $self.'/revisions',
            ],
        ];
    }

    /**
     * @param array{
     *     page: int,
     *     limit: int,
     *     status: string,
     *     statuses: list<ContentStatus>|null,
     *     schema: string,
     *     parent: string,
     *     parent_uid: string|null,
     *     sort: string,
     *     order_by: array<string, 'ASC'|'DESC'>
     * } $query
     *
     * @return array{
     *     items: list<array<string, mixed>>,
     *     pagination: array{page: int, limit: int, returned: int},
     *     filters: array{status: string, schema: string, parent: string, sort: string}
     * }
     */
    private function emptyList(array $query): array
    {
        return [
            'items' => [],
            'pagination' => [
                'page' => $query['page'],
                'limit' => $query['limit'],
                'returned' => 0,
            ],
            'filters' => [
                'status' => $query['status'],
                'schema' => $query['schema'],
                'parent' => $query['parent'],
                'sort' => $query['sort'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function versionResource(ContentRevision $revision, bool $active): array
    {
        return [
            'type' => 'content_version',
            'id' => (string) $revision->version(),
            'attributes' => [
                'version' => $revision->version(),
                'active' => $active,
                'schema' => $revision->schema()->identifier(),
                'schema_version' => $revision->schemaVersion()->version(),
            ],
        ];
    }
}
