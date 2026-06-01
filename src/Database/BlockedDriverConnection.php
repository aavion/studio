<?php

declare(strict_types=1);

namespace App\Database;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;

final readonly class BlockedDriverConnection implements Connection
{
    public function prepare(string $sql): Statement
    {
        return new BlockedDriverStatement();
    }

    public function query(string $sql): Result
    {
        return new EmptyDriverResult();
    }

    public function quote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    public function exec(string $sql): int|string
    {
        return 0;
    }

    public function lastInsertId(): int|string
    {
        return 0;
    }

    public function beginTransaction(): void
    {
    }

    public function commit(): void
    {
    }

    public function rollBack(): void
    {
    }

    public function getNativeConnection(): object
    {
        return $this;
    }

    public function getServerVersion(): string
    {
        return '0.0.0';
    }
}

final readonly class BlockedDriverStatement implements Statement
{
    public function bindValue(int|string $param, mixed $value, ParameterType $type): void
    {
    }

    public function execute(): Result
    {
        return new EmptyDriverResult();
    }
}

final readonly class EmptyDriverResult implements Result
{
    public function fetchNumeric(): array|false
    {
        return false;
    }

    public function fetchAssociative(): array|false
    {
        return false;
    }

    public function fetchOne(): mixed
    {
        return false;
    }

    public function fetchAllNumeric(): array
    {
        return [];
    }

    public function fetchAllAssociative(): array
    {
        return [];
    }

    public function fetchFirstColumn(): array
    {
        return [];
    }

    public function rowCount(): int|string
    {
        return 0;
    }

    public function columnCount(): int
    {
        return 0;
    }

    public function free(): void
    {
    }
}
