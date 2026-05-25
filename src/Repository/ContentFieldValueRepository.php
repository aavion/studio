<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ContentFieldValue;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContentFieldValue>
 */
final class ContentFieldValueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContentFieldValue::class);
    }

    /**
     * @return list<ContentFieldValue>
     */
    public function findForRevisionContext(string $revisionUid, string $language, string $variant = 'default'): array
    {
        return $this->createQueryBuilder('field_value')
            ->andWhere('IDENTITY(field_value.revision) = :revisionUid')
            ->andWhere('field_value.language = :language')
            ->andWhere('field_value.variant = :variant')
            ->setParameter('revisionUid', $revisionUid)
            ->setParameter('language', $language)
            ->setParameter('variant', $variant)
            ->orderBy('field_value.fieldIdentifier', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
