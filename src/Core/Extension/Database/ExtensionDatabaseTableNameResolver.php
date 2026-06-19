<?php

declare(strict_types=1);

namespace App\Core\Extension\Database;

use App\Database\TablePrefix;
use App\Entity\Extension;
use Doctrine\DBAL\Connection;

final readonly class ExtensionDatabaseTableNameResolver
{
    public function __construct(private Connection $connection)
    {
    }

    public function tableName(Extension $extension, ExtensionDatabaseTable $table): string
    {
        return $table->physicalName($extension->extensionName(), $this->databasePrefix());
    }

    public function referencedTableName(Extension $extension, ExtensionDatabaseForeignKey $foreignKey): string
    {
        return $this->databasePrefix().str_replace('-', '_', $extension->extensionName()).'_'.$foreignKey->referencedTable();
    }

    public function isOwnedTableName(Extension $extension, string $tableName): bool
    {
        $ownedPrefix = $this->databasePrefix().str_replace('-', '_', $extension->extensionName()).'_';

        return str_starts_with($tableName, $ownedPrefix);
    }

    public function shortName(string $name): string
    {
        return strlen($name) <= 63 ? $name : substr($name, 0, 48).'_'.substr(hash('sha256', $name), 0, 14);
    }

    public function databasePrefix(): string
    {
        $params = $this->connection->getParams();
        $prefix = $params['system_database_prefix'] ?? null;

        return is_string($prefix) ? TablePrefix::normalize($prefix) : TablePrefix::fromEnvironment();
    }
}
