<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Database\TablePrefix;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Throwable;

final readonly class SetupRollbacker
{
    public function __construct(
        private SetupDatabaseConnectionFactory $connectionFactory = new SetupDatabaseConnectionFactory(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function rollback(
        string $projectDir,
        SetupInput $input,
        string $databaseUrl,
        ?SetupEnvironmentSnapshot $environmentSnapshot = null,
        ?SetupDatabaseTableSnapshot $tableSnapshot = null,
    ): array
    {
        if ($input->dryRun()) {
            return ['rollback' => ['skipped' => 'dry_run']];
        }

        $environmentFiles = $this->restoreEnvironmentFiles($projectDir, $input->appEnv(), $environmentSnapshot);

        $tableSnapshot ??= SetupDatabaseTableSnapshot::capture($projectDir, $databaseUrl, $input->appEnv(), $input->databasePrefix());
        $databaseTables = $this->removeDatabaseTables($projectDir, $input, $databaseUrl, $tableSnapshot);
        $rollback = [
            'env_files_removed' => $environmentFiles['removed'],
            'env_files_restored' => $environmentFiles['restored'],
            'env_files_restore_errors' => $environmentFiles['errors'],
            'sqlite_files_removed' => [],
            'database_table_snapshot_error' => $tableSnapshot->error(),
            'database_tables_removed' => $databaseTables,
        ];

        return [
            'rollback' => $rollback,
            '_messages' => [$this->rollbackMessage($rollback)],
        ];
    }

    /**
     * @param array{env_files_removed: list<string>, env_files_restored: list<string>, env_files_restore_errors: list<array{file: string, error: string}>, sqlite_files_removed: list<string>, database_table_snapshot_error: string|null, database_tables_removed: array{tables: list<string>, skipped?: list<string>, error?: string}} $rollback
     */
    private function rollbackMessage(array $rollback): Message
    {
        $errors = count($rollback['env_files_restore_errors'])
            + (isset($rollback['database_tables_removed']['error']) ? 1 : 0)
            + (null !== $rollback['database_table_snapshot_error'] ? 1 : 0);
        $parameters = [
            '%env_removed%' => (string) count($rollback['env_files_removed']),
            '%env_restored%' => (string) count($rollback['env_files_restored']),
            '%tables_removed%' => (string) count($rollback['database_tables_removed']['tables']),
            '%errors%' => (string) $errors,
        ];
        $context = [
            'rollback' => $rollback,
            'env_removed' => $rollback['env_files_removed'],
            'env_restored' => $rollback['env_files_restored'],
            'database_tables_removed' => $rollback['database_tables_removed']['tables'],
            'rollback_error_count' => $errors,
        ];

        if ($errors > 0) {
            return Message::warning(MessageCode::SETUP_ROLLBACK_COMPLETED, MessageKey::SETUP_ROLLBACK_COMPLETED, $parameters, $context);
        }

        return Message::info(MessageCode::SETUP_ROLLBACK_COMPLETED, MessageKey::SETUP_ROLLBACK_COMPLETED, $parameters, $context);
    }

    /**
     * @return array{removed: list<string>, restored: list<string>, errors: list<array{file: string, error: string}>}
     */
    private function restoreEnvironmentFiles(string $projectDir, string $environment, ?SetupEnvironmentSnapshot $snapshot): array
    {
        if (null !== $snapshot) {
            return $snapshot->restore();
        }

        return ['removed' => $this->removeEnvironmentFiles($projectDir, $environment), 'restored' => [], 'errors' => []];
    }

    /**
     * @return list<string>
     */
    private function removeEnvironmentFiles(string $projectDir, string $environment): array
    {
        $removed = [];

        foreach ([$projectDir.'/.env.'.$environment.'.local', $projectDir.'/.env.local.php'] as $path) {
            if (is_file($path) && !is_link($path)) {
                unlink($path);
                $removed[] = basename($path);
            }
        }

        return $removed;
    }

    /**
     * @return array{tables: list<string>, skipped?: list<string>, error?: string}
     */
    private function removeDatabaseTables(
        string $projectDir,
        SetupInput $input,
        string $databaseUrl,
        SetupDatabaseTableSnapshot $tableSnapshot,
    ): array
    {
        if (!$tableSnapshot->reliable()) {
            return [
                'tables' => [],
                'skipped' => $this->rollbackTables($input),
                'error' => 'Setup table snapshot is unavailable; table rollback was skipped.',
            ];
        }

        $connection = null;

        try {
            $connection = $this->connectionFactory->create($projectDir, $databaseUrl, $input->appEnv(), $input->databasePrefix());
            $platform = $connection->getDatabasePlatform();

            return match (true) {
                $platform instanceof AbstractMySQLPlatform => $this->dropMySqlTables($connection, $input, $tableSnapshot),
                $platform instanceof PostgreSQLPlatform => $this->dropPostgreSqlTables($connection, $input, $tableSnapshot),
                $platform instanceof SQLitePlatform => $this->dropSqliteTables($connection, $input, $tableSnapshot),
                default => ['tables' => [], 'error' => sprintf('Unsupported rollback database platform "%s".', $platform::class)],
            };
        } catch (Throwable $throwable) {
            return ['tables' => [], 'error' => $throwable->getMessage()];
        } finally {
            $connection?->close();
        }
    }

    /**
     * @return array{tables: list<string>, skipped: list<string>}
     */
    private function dropMySqlTables(Connection $connection, SetupInput $input, SetupDatabaseTableSnapshot $snapshot): array
    {
        $removed = [];
        $skipped = [];
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($this->rollbackTables($input) as $table) {
                if ($snapshot->existed($table)) {
                    $skipped[] = $table;

                    continue;
                }

                $connection->executeStatement(sprintf(
                    'DROP TABLE IF EXISTS %s',
                    $connection->getDatabasePlatform()->quoteSingleIdentifier($table),
                ));
                $removed[] = $table;
            }
        } finally {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
        }

        return ['tables' => $removed, 'skipped' => $skipped];
    }

    /**
     * @return array{tables: list<string>, skipped: list<string>}
     */
    private function dropPostgreSqlTables(Connection $connection, SetupInput $input, SetupDatabaseTableSnapshot $snapshot): array
    {
        $removed = [];
        $skipped = [];

        foreach ($this->rollbackTables($input) as $table) {
            if ($snapshot->existed($table)) {
                $skipped[] = $table;

                continue;
            }

            $connection->executeStatement(sprintf(
                'DROP TABLE IF EXISTS %s CASCADE',
                $connection->getDatabasePlatform()->quoteSingleIdentifier($table),
            ));
            $removed[] = $table;
        }

        return ['tables' => $removed, 'skipped' => $skipped];
    }

    /**
     * @return array{tables: list<string>, skipped: list<string>}
     */
    private function dropSqliteTables(Connection $connection, SetupInput $input, SetupDatabaseTableSnapshot $snapshot): array
    {
        $removed = [];
        $skipped = [];
        $connection->executeStatement('PRAGMA foreign_keys=OFF');

        try {
            foreach ($this->rollbackTables($input) as $table) {
                if ($snapshot->existed($table)) {
                    $skipped[] = $table;

                    continue;
                }

                $connection->executeStatement(sprintf(
                    'DROP TABLE IF EXISTS %s',
                    $connection->getDatabasePlatform()->quoteSingleIdentifier($table),
                ));
                $removed[] = $table;
            }
        } finally {
            $connection->executeStatement('PRAGMA foreign_keys=ON');
        }

        return ['tables' => $removed, 'skipped' => $skipped];
    }

    /**
     * @return list<string>
     */
    private function rollbackTables(SetupInput $input): array
    {
        $prefix = $input->databasePrefix() ?? '';

        $tables = array_map(
            static fn (string $table): string => TablePrefix::apply($table, $prefix),
            array_reverse(TablePrefix::TABLES),
        );
        $tables[] = TablePrefix::MIGRATION_TABLE;

        return array_values(array_unique($tables));
    }

}
