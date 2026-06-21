<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Extension\Database\ExtensionDatabaseTableNameResolver;
use App\Core\Validation\IdentifierSpec;
use Doctrine\DBAL\Connection;
use Throwable;

final readonly class ExtensionDatabaseFacade
{
    public const MAX_FETCH_ROWS = 100;
    public const MAX_VALUE_BYTES = 1048576;

    public function __construct(
        private Connection $connection,
        private ExtensionDatabaseTableNameResolver $tableNames,
    ) {
    }

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, mixed> $options
     * @return list<array<string, mixed>>
     */
    public function fetch(string $extensionName, string $table, array $criteria = [], array $options = []): array
    {
        $tableName = $this->tableName($extensionName, $table);
        $criteria = $this->normalizeRecord($criteria, allowEmpty: true);
        if (null === $tableName || null === $criteria) {
            return [];
        }

        try {
            [$where, $params] = $this->where($criteria);
            $limit = $this->limit($options['limit'] ?? self::MAX_FETCH_ROWS);
            $offset = max(0, min(100000, is_int($options['offset'] ?? null) ? $options['offset'] : (int) ($options['offset'] ?? 0)));
            $sql = 'SELECT * FROM '.$this->connection->quoteSingleIdentifier($tableName).$where;

            if (is_string($options['order_by'] ?? null) && IdentifierSpec::isPortableDatabaseIdentifier($options['order_by'])) {
                $direction = strtolower((string) ($options['direction'] ?? 'asc'));
                $sql .= ' ORDER BY '.$this->connection->quoteSingleIdentifier($options['order_by']).('desc' === $direction ? ' DESC' : ' ASC');
            }

            $sql .= ' LIMIT '.$limit.' OFFSET '.$offset;
            $rows = $this->connection->executeQuery($sql, $params)->fetchAllAssociative();

            return array_values(array_filter($rows, static fn (mixed $row): bool => is_array($row)));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    public function insert(string $extensionName, string $table, array $row): bool
    {
        $tableName = $this->tableName($extensionName, $table);
        $row = $this->normalizeRecord($row);
        if (null === $tableName || null === $row) {
            return false;
        }

        try {
            return 1 === $this->connection->insert($tableName, $row);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, mixed> $values
     */
    public function update(string $extensionName, string $table, array $criteria, array $values): int
    {
        $tableName = $this->tableName($extensionName, $table);
        $criteria = $this->normalizeRecord($criteria);
        $values = $this->normalizeRecord($values);
        if (null === $tableName || null === $criteria || null === $values) {
            return 0;
        }

        try {
            return $this->connection->update($tableName, $values, $criteria);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @param array<string, mixed> $criteria
     */
    public function delete(string $extensionName, string $table, array $criteria): int
    {
        $tableName = $this->tableName($extensionName, $table);
        $criteria = $this->normalizeRecord($criteria);
        if (null === $tableName || null === $criteria) {
            return 0;
        }

        try {
            return $this->connection->delete($tableName, $criteria);
        } catch (Throwable) {
            return 0;
        }
    }

    private function tableName(string $extensionName, string $table): ?string
    {
        if (!ExtensionManifestSpec::isValidSlug($extensionName) || !IdentifierSpec::isPortableDatabaseIdentifier($table)) {
            return null;
        }

        return $this->tableNames->databasePrefix().ExtensionOwnerName::prefix($extensionName).$table;
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, bool|float|int|string|null>|null
     */
    private function normalizeRecord(array $record, bool $allowEmpty = false): ?array
    {
        if ([] === $record && !$allowEmpty) {
            return null;
        }

        $normalized = [];
        foreach ($record as $column => $value) {
            if (!is_string($column) || !IdentifierSpec::isPortableDatabaseIdentifier($column)) {
                return null;
            }

            $valid = true;
            $value = $this->normalizeValue($value, $valid);
            if (!$valid) {
                return null;
            }

            $normalized[$column] = $value;
        }

        return $normalized;
    }

    private function normalizeValue(mixed $value, bool &$valid): bool|float|int|string|null
    {
        if (null === $value || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
            return $this->boundedValue($value, $valid);
        }

        if (is_array($value)) {
            try {
                return $this->boundedValue(json_encode($value, JSON_THROW_ON_ERROR), $valid);
            } catch (Throwable) {
                $valid = false;

                return null;
            }
        }

        $valid = false;

        return null;
    }

    private function boundedValue(bool|float|int|string|null $value, bool &$valid): bool|float|int|string|null
    {
        if (is_string($value) && strlen($value) > self::MAX_VALUE_BYTES) {
            $valid = false;

            return null;
        }

        return $value;
    }

    /**
     * @param array<string, bool|float|int|string|null> $criteria
     * @return array{0: string, 1: array<string, bool|float|int|string|null>}
     */
    private function where(array $criteria): array
    {
        if ([] === $criteria) {
            return ['', []];
        }

        $clauses = [];
        $params = [];
        $index = 0;
        foreach ($criteria as $column => $value) {
            $quotedColumn = $this->connection->quoteSingleIdentifier($column);
            if (null === $value) {
                $clauses[] = $quotedColumn.' IS NULL';
                continue;
            }

            $param = 'c'.$index++;
            $clauses[] = $quotedColumn.' = :'.$param;
            $params[$param] = $value;
        }

        return [' WHERE '.implode(' AND ', $clauses), $params];
    }

    private function limit(mixed $limit): int
    {
        $limit = is_int($limit) ? $limit : (int) $limit;

        return max(1, min(self::MAX_FETCH_ROWS, $limit));
    }
}
