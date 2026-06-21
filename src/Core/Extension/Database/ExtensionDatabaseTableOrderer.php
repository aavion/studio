<?php

declare(strict_types=1);

namespace App\Core\Extension\Database;

use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;
use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Workflow\WorkflowResult;
use App\Entity\Extension;
use Doctrine\DBAL\Connection;
use Throwable;

final readonly class ExtensionDatabaseTableOrderer
{
    public function __construct(
        private Connection $connection,
        private ExtensionDatabaseTableNameResolver $names,
    ) {
    }

    /**
     * @param array<string, ExtensionDatabaseTable> $pendingTables
     *
     * @return list<string>|WorkflowResult<null>
     */
    public function pendingTablesForCreation(Extension $extension, array $pendingTables): array|WorkflowResult
    {
        $ordered = [];
        $visited = [];
        $visiting = [];

        $visit = function (string $physicalName) use (&$visit, &$ordered, &$visited, &$visiting, $extension, $pendingTables): bool {
            if (isset($visited[$physicalName])) {
                return true;
            }

            if (isset($visiting[$physicalName])) {
                return false;
            }

            $visiting[$physicalName] = true;

            foreach ($pendingTables[$physicalName]->foreignKeys() as $foreignKey) {
                $referencedPhysicalName = $this->names->referencedTableName($extension, $foreignKey);
                if ($referencedPhysicalName === $physicalName) {
                    continue;
                }

                if (isset($pendingTables[$referencedPhysicalName]) && !$visit($referencedPhysicalName)) {
                    return false;
                }
            }

            unset($visiting[$physicalName]);
            $visited[$physicalName] = true;
            $ordered[] = $physicalName;

            return true;
        };

        foreach (array_keys($pendingTables) as $physicalName) {
            if (!$visit($physicalName)) {
                return $this->invalid($extension, 'foreign_key_reference_cycle', ['table' => $physicalName]);
            }
        }

        return $ordered;
    }

    /**
     * @param list<string> $tableNames
     *
     * @return list<string>
     */
    public function existingTablesForDrop(array $tableNames): array
    {
        $ownedTables = array_fill_keys($tableNames, true);
        $referencedBy = [];

        foreach ($tableNames as $tableName) {
            $referencedBy[$tableName] = [];
        }

        $schemaManager = $this->connection->createSchemaManager();

        foreach ($tableNames as $tableName) {
            try {
                $table = $schemaManager->introspectTable($tableName);
            } catch (Throwable) {
                continue;
            }

            foreach ($table->getForeignKeys() as $foreignKey) {
                $referencedTable = $foreignKey->getForeignTableName();
                if ($referencedTable === $tableName || !isset($ownedTables[$referencedTable])) {
                    continue;
                }

                $referencedBy[$referencedTable][] = $tableName;
            }
        }

        $ordered = [];
        $visited = [];
        $visiting = [];

        $visit = function (string $tableName) use (&$visit, &$ordered, &$visited, &$visiting, $referencedBy): void {
            if (isset($visited[$tableName])) {
                return;
            }

            if (isset($visiting[$tableName])) {
                return;
            }

            $visiting[$tableName] = true;

            foreach ($referencedBy[$tableName] ?? [] as $referencingTable) {
                $visit($referencingTable);
            }

            unset($visiting[$tableName]);
            $visited[$tableName] = true;
            $ordered[] = $tableName;
        };

        foreach ($tableNames as $tableName) {
            $visit($tableName);
        }

        return $ordered;
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
}
