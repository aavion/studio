<?php

declare(strict_types=1);

namespace App\Database;

use App\Setup\SetupCompletionMarker;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Statement;

final class PrefixedConnection extends Connection
{
    protected function connect(): DriverConnection
    {
        if (!$this->databaseAccessAllowed()) {
            return $this->_conn ??= new BlockedDriverConnection();
        }

        return parent::connect();
    }

    public function prepare(string $sql): Statement
    {
        return parent::prepare($this->prefixSql($sql));
    }

    public function executeQuery(
        string $sql,
        array $params = [],
        array $types = [],
        ?QueryCacheProfile $qcp = null,
    ): Result {
        return parent::executeQuery($this->prefixSql($sql), $params, $types, $qcp);
    }

    public function executeStatement(string $sql, array $params = [], array $types = []): int|string
    {
        return parent::executeStatement($this->prefixSql($sql), $params, $types);
    }

    public function insert(string $table, array $data, array $types = []): int|string
    {
        return parent::insert($this->prefixTableExpression($table), $data, $types);
    }

    public function update(string $table, array $data, array $criteria = [], array $types = []): int|string
    {
        return parent::update($this->prefixTableExpression($table), $data, $criteria, $types);
    }

    public function delete(string $table, array $criteria = [], array $types = []): int|string
    {
        return parent::delete($this->prefixTableExpression($table), $criteria, $types);
    }

    private function prefixTableExpression(string $table): string
    {
        $prefix = $this->prefix();

        if ('' === $prefix) {
            return $table;
        }

        if (str_contains($table, '.')) {
            [$schema, $name] = explode('.', $table, 2);

            return $schema.'.'.TablePrefix::apply($name, $prefix);
        }

        return TablePrefix::apply($table, $prefix);
    }

    private function databaseAccessAllowed(): bool
    {
        $params = $this->getParams();

        if (($params['system_allow_unready_database'] ?? false) === true) {
            return true;
        }

        return $this->truthy($_SERVER[SetupCompletionMarker::KEY] ?? $_ENV[SetupCompletionMarker::KEY] ?? getenv(SetupCompletionMarker::KEY))
            || $this->truthy($_SERVER[DatabaseReadyState::ALLOW_UNREADY_KEY] ?? $_ENV[DatabaseReadyState::ALLOW_UNREADY_KEY] ?? getenv(DatabaseReadyState::ALLOW_UNREADY_KEY));
    }

    private function truthy(mixed $value): bool
    {
        return is_string($value) && in_array(strtolower(trim($value, " \t\n\r\0\x0B'\"")), ['1', 'true', 'yes'], true);
    }

    private function prefixSql(string $sql): string
    {
        $prefix = $this->prefix();

        if ('' === $prefix) {
            return $sql;
        }

        $tables = TablePrefix::TABLES;
        usort($tables, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        foreach ($tables as $table) {
            $sql = preg_replace_callback(
                '/(\b(?:FROM|JOIN|INTO|UPDATE|TABLE|REFERENCES|ON)\s+)([`"]?)('.preg_quote($table, '/').')(\2)(?![A-Za-z0-9_])/i',
                static fn (array $matches): string => $matches[1].$matches[2].TablePrefix::apply($matches[3], $prefix).$matches[4],
                $sql,
            ) ?? $sql;
        }

        return $sql;
    }

    private function prefix(): string
    {
        $params = $this->getParams();
        $prefix = $params['system_database_prefix'] ?? null;

        return is_string($prefix) ? TablePrefix::normalize($prefix) : TablePrefix::fromEnvironment();
    }
}
