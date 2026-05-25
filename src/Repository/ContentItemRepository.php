<?php

declare(strict_types=1);

namespace App\Repository;

use App\Content\ContentStatus;
use App\Content\Routing\ContentSystemRoute;
use App\Entity\ContentItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContentItem>
 */
final class ContentItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContentItem::class);
    }

    public function findOnePublishedBySlug(string $slug): ?ContentItem
    {
        return $this->findOneBy([
            'slug' => $slug,
            'parentUid' => ContentSystemRoute::ROOT_PARENT_UID,
            'status' => ContentStatus::Published,
        ]);
    }

    public function findOneContentBySlug(string $slug): ?ContentItem
    {
        return $this->findOneBy([
            'slug' => $slug,
            'parentUid' => ContentSystemRoute::ROOT_PARENT_UID,
        ]);
    }

    public function findOnePublishedBySlugAndParentUid(string $slug, ?string $parentUid): ?ContentItem
    {
        return $this->findOneBy([
            'slug' => $slug,
            'parentUid' => $this->normalizeParentUid($parentUid),
            'status' => ContentStatus::Published,
        ]);
    }

    public function findOneContentBySlugAndParentUid(string $slug, ?string $parentUid): ?ContentItem
    {
        return $this->findOneBy([
            'slug' => $slug,
            'parentUid' => $this->normalizeParentUid($parentUid),
        ]);
    }

    public function findOnePublishedByCustomUrl(string $customUrl): ?ContentItem
    {
        return $this->findOneBy([
            'customUrl' => $customUrl,
            'status' => ContentStatus::Published,
        ]);
    }

    public function findOneContentByCustomUrl(string $customUrl): ?ContentItem
    {
        return $this->findOneBy([
            'customUrl' => $customUrl,
        ]);
    }

    private function normalizeParentUid(?string $parentUid): string
    {
        return $parentUid ?? ContentSystemRoute::ROOT_PARENT_UID;
    }
}
