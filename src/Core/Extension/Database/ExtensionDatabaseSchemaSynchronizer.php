<?php

declare(strict_types=1);

namespace App\Core\Extension\Database;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;
use App\Core\Workflow\WorkflowResult;
use App\Database\TablePrefix;
use App\Entity\Extension;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Throwable;

final readonly class ExtensionDatabaseSchemaSynchronizer
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param iterable<ExtensionDatabaseTable> $tables
     *
     * @return WorkflowResult<array{created: list<string>, existing: list<string>}>
     */
    public function apply(Extension $extension, iterable $tables): WorkflowResult
    {
        $created = [];
        $existing = [];
        $schemaManager = $this->connection->createSchemaManager();
        $knownTables = array_map('strtolower', $schemaManager->listTableNames());

        foreach ($tables as $table) {
            if (!$table instanceof ExtensionDatabaseTable) {
                return $this->invalid($extension, 'table_invalid');
            }

            $physicalName = $table->physicalName($extension->extensionName(), $this->databasePrefix());
            if (!$this->isOwnedTableName($extension, $physicalName)) {
                return $this->invalid($extension, 'table_name_not_owned', ['table' => $physicalName]);
            }

            if (in_array(strtolower($physicalName), $knownTables, true)) {
                $existing[] = $physicalName;
                continue;
            }

            try {
                $this->createTable($physicalName, $table);
            } catch (Throwable $error) {
                return WorkflowResult::failed([
                    Message::create(
                        ExtensionMessageCode::EXTENSION_DATABASE_CONTRIBUTION_INVALID,
                        ExtensionMessageKey::EXTENSION_DATABASE_CONTRIBUTION_INVALID,
                        ['%reason%' => 'create_table_failed'],
                        ['extension' => $extension->extensionName(), 'table' => $physicalName, 'exception' => $error::class, 'message' => $error->getMessage()],
                        MessageLevel::Exception,
                    ),
                ]);
            }

            $created[] = $physicalName;
            $knownTables[] = strtolower($physicalName);
        }

        return WorkflowResult::success([
            'created' => $created,
            'existing' => $existing,
        ], [
            'extension' => $extension->extensionName(),
            'created' => $created,
            'existing' => $existing,
        ], [
            Message::debug(
                ExtensionMessageCode::EXTENSION_DATABASE_SYNC_COMPLETED,
                ExtensionMessageKey::EXTENSION_DATABASE_SYNC_COMPLETED,
                ['%extension%' => $extension->extensionName(), '%count%' => count($created)],
                ['extension' => $extension->extensionName(), 'created' => $created, 'existing' => $existing],
            ),
        ]);
    }

    /**
     * @return WorkflowResult<array{dropped: list<string>}>
     */
    public function purge(Extension $extension): WorkflowResult
    {
        $dropped = [];
        $platform = $this->connection->getDatabasePlatform();
        $schemaManager = $this->connection->createSchemaManager();

        foreach ($schemaManager->listTableNames() as $tableName) {
            if (!$this->isOwnedTableName($extension, $tableName)) {
                continue;
            }

            foreach ((array) $platform->getDropTableSQL($tableName) as $sql) {
                $this->connection->executeStatement($sql);
            }

            $dropped[] = $tableName;
        }

        return WorkflowResult::success([
            'dropped' => $dropped,
        ], [
            'extension' => $extension->extensionName(),
            'dropped' => $dropped,
        ], [
            Message::debug(
                ExtensionMessageCode::EXTENSION_DATABASE_PURGE_COMPLETED,
                ExtensionMessageKey::EXTENSION_DATABASE_PURGE_COMPLETED,
                ['%extension%' => $extension->extensionName(), '%count%' => count($dropped)],
                ['extension' => $extension->extensionName(), 'dropped' => $dropped],
            ),
        ]);
    }

    private function createTable(string $physicalName, ExtensionDatabaseTable $definition): void
    {
        $table = new Table($physicalName);

        foreach ($definition->columns() as $column) {
            $table->addColumn($column->name(), $column->type(), $column->options());
        }

        if ([] !== $definition->primaryKey()) {
            $table->setPrimaryKey($definition->primaryKey(), $this->shortName('pk_'.$physicalName));
        }

        foreach ($definition->indexes() as $index) {
            $name = $this->shortName($physicalName.'_'.$index->name());
            if ($index->unique()) {
                $table->addUniqueIndex($index->columns(), $name);
                continue;
            }

            $table->addIndex($index->columns(), $name);
        }

        foreach ($this->connection->getDatabasePlatform()->getCreateTableSQL($table) as $sql) {
            $this->connection->executeStatement($sql);
        }
    }

    private function isOwnedTableName(Extension $extension, string $tableName): bool
    {
        $ownedPrefix = $this->databasePrefix().str_replace('-', '_', $extension->extensionName()).'_';

        return str_starts_with($tableName, $ownedPrefix);
    }

    private function databasePrefix(): string
    {
        $params = $this->connection->getParams();
        $prefix = $params['system_database_prefix'] ?? null;

        return is_string($prefix) ? TablePrefix::normalize($prefix) : TablePrefix::fromEnvironment();
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return WorkflowResult<null>
     */
    private function invalid(Extension $extension, string $reason, array $context = []): WorkflowResult
    {
        return WorkflowResult::invalid([
            Message::create(
                ExtensionMessageCode::EXTENSION_DATABASE_CONTRIBUTION_INVALID,
                ExtensionMessageKey::EXTENSION_DATABASE_CONTRIBUTION_INVALID,
                ['%reason%' => $reason],
                ['extension' => $extension->extensionName(), ...$context],
                MessageLevel::Error,
            ),
        ]);
    }

    private function shortName(string $name): string
    {
        return strlen($name) <= 63 ? $name : substr($name, 0, 48).'_'.substr(hash('sha256', $name), 0, 14);
    }
}
