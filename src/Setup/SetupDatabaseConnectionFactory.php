<?php

declare(strict_types=1);

namespace App\Setup;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

final readonly class SetupDatabaseConnectionFactory
{
    public function create(string $projectDir, string $databaseUrl): Connection
    {
        return DriverManager::getConnection($this->connectionParameters(str_replace('%kernel.project_dir%', $projectDir, $databaseUrl)));
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
}
