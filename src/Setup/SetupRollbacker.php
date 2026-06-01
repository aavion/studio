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
    public function rollback(string $projectDir, SetupInput $input, string $databaseUrl, ?SetupEnvironmentSnapshot $environmentSnapshot = null): array
    {
        if ($input->dryRun()) {
            return ['rollback' => ['skipped' => 'dry_run']];
        }

        $environmentFiles = $this->restoreEnvironmentFiles($projectDir, $input->appEnv(), $environmentSnapshot);

        $databaseTables = $this->removeDatabaseTables($projectDir, $input, $databaseUrl);
        $rollback = [
            'env_files_removed' => $environmentFiles['removed'],
            'env_files_restored' => $environmentFiles['restored'],
            'env_files_restore_errors' => $environmentFiles['errors'],
            'sqlite_files_removed' => [],
            'database_tables_removed' => $databaseTables,
        ];

        return [
            'rollback' => $rollback,
            '_messages' => [$this->rollbackMessage($rollback)],
        ];
    }

    /**
     * @param array{env_files_removed: list<string>, env_files_restored: list<string>, env_files_restore_errors: list<array{file: string, error: string}>, sqlite_files_removed: list<string>, database_tables_removed: array{tables: list<string>, error?: string}} $rollback
     */
    private function rollbackMessage(array $rollback): Message
    {
        $errors = count($rollback['env_files_restore_errors']) + (isset($rollback['database_tables_removed']['error']) ? 1 : 0);
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
     * @return array{tables: list<string>, error?: string}
     */
    private function removeDatabaseTables(string $projectDir, SetupInput $input, string $databaseUrl): array
    {
        $connection = null;

        try {
            $connection = $this->connectionFactory->create($projectDir, $databaseUrl, $input->appEnv());
            $platform = $connection->getDatabasePlatform();

            return match (true) {
                $platform instanceof AbstractMySQLPlatform => ['tables' => $this->dropMySqlTables($connection, $input)],
                $platform instanceof PostgreSQLPlatform => ['tables' => $this->dropPostgreSqlTables($connection, $input)],
                $platform instanceof SQLitePlatform => ['tables' => $this->dropSqliteTables($connection, $input)],
                default => ['tables' => [], 'error' => sprintf('Unsupported rollback database platform "%s".', $platform::class)],
            };
        } catch (Throwable $throwable) {
            return ['tables' => [], 'error' => $throwable->getMessage()];
        } finally {
            $connection?->close();
        }
    }

    /**
     * @return list<string>
     */
    private function dropMySqlTables(Connection $connection, SetupInput $input): array
    {
        $removed = [];
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($this->rollbackTables($input) as $table) {
                $connection->executeStatement(sprintf(
                    'DROP TABLE IF EXISTS %s',
                    $connection->getDatabasePlatform()->quoteSingleIdentifier($table),
                ));
                $removed[] = $table;
            }
        } finally {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
        }

        return $removed;
    }

    /**
     * @return list<string>
     */
    private function dropPostgreSqlTables(Connection $connection, SetupInput $input): array
    {
        $removed = [];

        foreach ($this->rollbackTables($input) as $table) {
            $connection->executeStatement(sprintf(
                'DROP TABLE IF EXISTS %s CASCADE',
                $connection->getDatabasePlatform()->quoteSingleIdentifier($table),
            ));
            $removed[] = $table;
        }

        return $removed;
    }

    /**
     * @return list<string>
     */
    private function dropSqliteTables(Connection $connection, SetupInput $input): array
    {
        $removed = [];
        $connection->executeStatement('PRAGMA foreign_keys=OFF');

        try {
            foreach ($this->rollbackTables($input) as $table) {
                $connection->executeStatement(sprintf(
                    'DROP TABLE IF EXISTS %s',
                    $connection->getDatabasePlatform()->quoteSingleIdentifier($table),
                ));
                $removed[] = $table;
            }
        } finally {
            $connection->executeStatement('PRAGMA foreign_keys=ON');
        }

        return $removed;
    }

    /**
     * @return list<string>
     */
    private function rollbackTables(SetupInput $input): array
    {
        $prefix = $input->databasePrefix() ?? '';

        return array_values(array_unique(array_map(
            static fn (string $table): string => TablePrefix::apply($table, $prefix),
            array_reverse(TablePrefix::TABLES),
        )));
    }

}
