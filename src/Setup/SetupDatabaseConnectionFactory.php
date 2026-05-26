<?php

declare(strict_types=1);

namespace App\Setup;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

final readonly class SetupDatabaseConnectionFactory
{
    public function create(string $projectDir, string $databaseUrl, ?string $appEnv = null): Connection
    {
        return DriverManager::getConnection($this->connectionParameters($this->resolveSymfonyPlaceholders(
            $databaseUrl,
            $projectDir,
            $appEnv,
        )));
    }

    /**
     * @return array<string, mixed>
     */
    private function connectionParameters(string $databaseUrl): array
    {
        if (str_starts_with($databaseUrl, 'sqlite:///')) {
            return ['driver' => 'pdo_sqlite', 'path' => preg_replace('#^sqlite:///#', '/', $databaseUrl)];
        }

        $scheme = (string) parse_url($databaseUrl, PHP_URL_SCHEME);

        return [
            'url' => $databaseUrl,
            'driver' => match ($scheme) {
                'mysql', 'mariadb' => 'pdo_mysql',
                'pgsql', 'postgres', 'postgresql' => 'pdo_pgsql',
                default => throw new SetupStepFailedException(sprintf('Unsupported database URL scheme "%s".', $scheme)),
            },
        ];
    }

    private function resolveSymfonyPlaceholders(string $databaseUrl, string $projectDir, ?string $appEnv): string
    {
        return strtr($databaseUrl, [
            '%kernel.project_dir%' => $projectDir,
            '%kernel.environment%' => $appEnv ?? (string) ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'dev'),
        ]);
    }
}
