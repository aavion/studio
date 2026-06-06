<?php

declare(strict_types=1);

namespace App\Security;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AclGroupReferenceQuery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $connection,
    ) {
    }

    /**
     * @param class-string<T> $className
     * @param list<string>    $columns
     *
     * @return list<T>
     *
     * @template T of object
     */
    public function entitiesByJsonColumns(string $className, string $table, array $columns, string $identifier): array
    {
        if ([] === $columns) {
            return [];
        }

        $pattern = '%'.json_encode($identifier, JSON_THROW_ON_ERROR).'%';
        $clauses = array_map(fn (string $column): string => $this->jsonLikeClause($column), $columns);

        return $this->entitiesByUid(
            $className,
            $this->connection->fetchFirstColumn(
                'SELECT uid FROM '.$table.' WHERE '.implode(' OR ', $clauses),
                array_fill(0, count($clauses), $pattern),
            ),
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $className
     * @param list<mixed>     $uids
     *
     * @return list<T>
     */
    private function entitiesByUid(string $className, array $uids): array
    {
        $uids = array_values(array_unique(array_filter($uids, 'is_string')));

        if ([] === $uids) {
            return [];
        }

        return array_values(array_filter(
            $this->entityManager->createQueryBuilder()
                ->select('entity')
                ->from($className, 'entity')
                ->andWhere('entity.uid IN (:uids)')
                ->setParameter('uids', $uids)
                ->getQuery()
                ->getResult(),
            static fn (mixed $entity): bool => $entity instanceof $className,
        ));
    }

    private function jsonLikeClause(string $column): string
    {
        if ($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return $column.'::text LIKE ?';
        }

        return $column.' LIKE ?';
    }
}
