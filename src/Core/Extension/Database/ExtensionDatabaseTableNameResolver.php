<?php

declare(strict_types=1);

namespace App\Core\Extension\Database;

use App\Database\TablePrefix;
use App\Core\Extension\ExtensionOwnerName;
use App\Entity\Extension;
use Doctrine\DBAL\Connection;

final readonly class ExtensionDatabaseTableNameResolver
{
    public const MAX_IDENTIFIER_LENGTH = 63;

    public function __construct(private Connection $connection)
    {
    }

    public function tableName(Extension $extension, ExtensionDatabaseTable $table): string
    {
        return $table->physicalName($extension->extensionName(), $this->databasePrefix());
    }

    public function referencedTableName(Extension $extension, ExtensionDatabaseForeignKey $foreignKey): string
    {
        return $this->databasePrefix().$this->extensionPrefix($extension).$foreignKey->referencedTable();
    }

    public function isOwnedTableName(Extension $extension, string $tableName): bool
    {
        return str_starts_with($tableName, $this->databasePrefix().$this->extensionPrefix($extension));
    }

    public function shortName(string $name): string
    {
        return strlen($name) <= self::MAX_IDENTIFIER_LENGTH ? $name : substr($name, 0, 48).'_'.substr(hash('sha256', $name), 0, 14);
    }

    public function isPortableIdentifier(string $name): bool
    {
        return strlen($name) <= self::MAX_IDENTIFIER_LENGTH;
    }

    public function databasePrefix(): string
    {
        $params = $this->connection->getParams();
        $prefix = $params['system_database_prefix'] ?? null;

        return is_string($prefix) ? TablePrefix::normalize($prefix) : TablePrefix::fromEnvironment();
    }

    private function extensionPrefix(Extension $extension): string
    {
        return ExtensionOwnerName::prefix($extension->extensionName());
    }
}
