<?php

declare(strict_types=1);

namespace App\Setup;

use App\Database\PrefixedConnection;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

final readonly class SetupDatabaseConnectionFactory
{
    public function create(string $projectDir, string $databaseUrl, ?string $appEnv = null, ?string $databasePrefix = null): Connection
    {
        $parameters = $this->connectionParameters($this->resolveSymfonyPlaceholders(
            $databaseUrl,
            $projectDir,
            $appEnv,
        ));

        if (null !== $databasePrefix) {
            $parameters['studio_database_prefix'] = $databasePrefix;
        }

        return DriverManager::getConnection($parameters);
    }

    /**
     * @return array<string, mixed>
     */
    private function connectionParameters(string $databaseUrl): array
    {
        if (str_starts_with($databaseUrl, 'sqlite:///')) {
            return [
                'driver' => 'pdo_sqlite',
                'path' => $this->sqlitePath($databaseUrl),
                'wrapperClass' => PrefixedConnection::class,
                'studio_allow_unready_database' => true,
            ];
        }

        $scheme = (string) parse_url($databaseUrl, PHP_URL_SCHEME);

        return [
            'url' => $databaseUrl,
            'wrapperClass' => PrefixedConnection::class,
            'studio_allow_unready_database' => true,
            'driver' => match ($scheme) {
                'mysql', 'mariadb' => 'pdo_mysql',
                'pgsql', 'postgres', 'postgresql' => 'pdo_pgsql',
                default => throw new SetupStepFailedException(sprintf('Unsupported database URL scheme "%s".', $scheme)),
            },
        ];
    }

    private function sqlitePath(string $databaseUrl): string
    {
        $path = urldecode(substr($databaseUrl, strlen('sqlite:///')));

        if (str_starts_with($path, '/')) {
            return $path;
        }

        if (1 === preg_match('/^[A-Za-z]:[\/\\\\]/', $path)) {
            return str_replace('\\', '/', $path);
        }

        return '/'.$path;
    }

    private function resolveSymfonyPlaceholders(string $databaseUrl, string $projectDir, ?string $appEnv): string
    {
        return strtr($databaseUrl, [
            '%kernel.project_dir%' => $projectDir,
            '%kernel.environment%' => $appEnv ?? (string) ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'dev'),
        ]);
    }
}
