<?php

declare(strict_types=1);

namespace App\Setup;

final readonly class DatabaseUrlFactory
{
    public function create(SetupInput $input, string $projectDir): string
    {
        if (null !== $input->databaseUrl() && '' !== trim($input->databaseUrl())) {
            return $this->validatedExplicitUrl($input->databaseUrl());
        }

        return match ($input->databaseDriver()) {
            DatabaseDriver::SQLite => sprintf('sqlite:///%%kernel.project_dir%%/var/data_%s.db', $input->appEnv()),
            DatabaseDriver::MySql => $this->serverUrl('mysql', $input, 3306),
            DatabaseDriver::PostgreSql => $this->serverUrl('postgresql', $input, 5432),
        };
    }

    private function validatedExplicitUrl(string $databaseUrl): string
    {
        if (str_starts_with($databaseUrl, 'sqlite:///')) {
            return $databaseUrl;
        }

        $scheme = parse_url($databaseUrl, PHP_URL_SCHEME);

        if (!is_string($scheme) || '' === $scheme) {
            throw new SetupStepFailedException('Database URL must include a supported scheme.');
        }

        if ('sqlite' === $scheme) {
            throw new SetupStepFailedException('SQLite database URLs must use the sqlite:///path/to/database.db format.');
        }

        return match ($scheme) {
            'mysql', 'mariadb', 'pgsql', 'postgres', 'postgresql' => $databaseUrl,
            default => throw new SetupStepFailedException(sprintf('Unsupported database URL scheme "%s".', $scheme)),
        };
    }

    private function serverUrl(string $scheme, SetupInput $input, int $defaultPort): string
    {
        $host = $input->databaseHost() ?? '127.0.0.1';
        $port = $input->databasePort() ?? $defaultPort;
        $name = $input->databaseName() ?? 'studio';
        $user = rawurlencode($input->databaseUser() ?? 'studio');
        $password = rawurlencode($input->databasePassword() ?? '');

        return sprintf('%s://%s:%s@%s:%d/%s', $scheme, $user, $password, $host, $port, rawurlencode($name));
    }
}
