<?php

declare(strict_types=1);

namespace App\Repository;

use App\Content\ContentStatus;
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
            'status' => ContentStatus::Published,
        ]);
    }
}
