<?php

declare(strict_types=1);

namespace App\Content\Api;

use App\Content\Read\ContentReadAccessPolicy;
use App\Core\Access\AccessActor;
use App\Entity\ContentItem;
use Doctrine\ORM\QueryBuilder;

final readonly class ContentApiVisibleItemPager
{
    public function __construct(private ContentReadAccessPolicy $accessPolicy)
    {
    }

    /**
     * @return list<ContentItem>
     */
    public function page(QueryBuilder $queryBuilder, int $page, int $limit, AccessActor $actor): array
    {
        $skipVisible = ($page - 1) * $limit;
        $visibleItems = [];
        $offset = 0;
        $batchSize = max($limit * 2, 100);

        while (count($visibleItems) < $limit) {
            /** @var list<ContentItem> $items */
            $items = (clone $queryBuilder)
                ->setFirstResult($offset)
                ->setMaxResults($batchSize)
                ->getQuery()
                ->getResult();

            if ([] === $items) {
                break;
            }

            foreach ($items as $item) {
                if (!$this->accessPolicy->allowsView($item, $actor)) {
                    continue;
                }

                if ($skipVisible > 0) {
                    --$skipVisible;

                    continue;
                }

                $visibleItems[] = $item;
                if (count($visibleItems) >= $limit) {
                    break;
                }
            }

            if (count($items) < $batchSize) {
                break;
            }

            $offset += $batchSize;
        }

        return $visibleItems;
    }
}
