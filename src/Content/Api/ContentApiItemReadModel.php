<?php

declare(strict_types=1);

namespace App\Content\Api;

use App\Content\ContentStatus;
use App\Content\ContentVisibility;
use App\Content\Read\ContentReadAccessPolicy;
use App\Core\Access\AccessActor;
use App\Entity\ContentItem;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ContentApiItemReadModel
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ContentReadAccessPolicy $accessPolicy,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function visibleItems(AccessActor $actor): array
    {
        $items = $this->entityManager->getRepository(ContentItem::class)->findBy(
            ['status' => ContentStatus::Published, 'visibility' => ContentVisibility::Public],
            ['sortOrder' => 'ASC', 'slug' => 'ASC'],
            100,
        );

        return array_values(array_filter(array_map(
            fn (ContentItem $item): ?array => $this->accessPolicy->allowsView($item, $actor) ? $this->resource($item) : null,
            $items,
        )));
    }

    private function resource(ContentItem $item): array
    {
        return [
            'type' => 'content_item',
            'id' => $item->slug(),
            'attributes' => [
                'slug' => $item->slug(),
                'parent_uid' => $item->parentUid(),
                'status' => $item->status()->value,
                'visibility' => $item->visibility()->value,
                'custom_url' => $item->customUrl(),
                'languages' => $item->availableLanguages(),
                'variants' => $item->availableVariants(),
                'schema' => $item->schema()?->identifier(),
                'schema_version' => $item->activeRevision()?->schemaVersion()->version(),
            ],
        ];
    }

}
