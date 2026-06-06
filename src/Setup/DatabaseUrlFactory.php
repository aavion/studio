<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\Message\Message;

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
            throw $this->failure(
                SetupMessageCode::SETUP_DATABASE_URL_SCHEME_MISSING,
                SetupMessageKey::SETUP_DATABASE_URL_SCHEME_MISSING,
            );
        }

        if ('sqlite' === $scheme) {
            throw $this->failure(
                SetupMessageCode::SETUP_DATABASE_URL_SQLITE_FORMAT_INVALID,
                SetupMessageKey::SETUP_DATABASE_URL_SQLITE_FORMAT_INVALID,
            );
        }

        return match ($scheme) {
            'mysql', 'mariadb', 'pgsql', 'postgres', 'postgresql' => $databaseUrl,
            default => throw $this->unsupportedScheme($scheme),
        };
    }

    private function serverUrl(string $scheme, SetupInput $input, int $defaultPort): string
    {
        $host = $input->databaseHost() ?? '127.0.0.1';
        $port = $input->databasePort() ?? $defaultPort;
        $name = $input->databaseName() ?? 'app';
        $user = rawurlencode($input->databaseUser() ?? 'app');
        $password = rawurlencode($input->databasePassword() ?? '');

        return sprintf('%s://%s:%s@%s:%d/%s', $scheme, $user, $password, $host, $port, rawurlencode($name));
    }

    private function unsupportedScheme(string $scheme): SetupStepFailedException
    {
        return $this->failure(
            SetupMessageCode::SETUP_DATABASE_URL_SCHEME_UNSUPPORTED,
            SetupMessageKey::SETUP_DATABASE_URL_SCHEME_UNSUPPORTED,
            ['%scheme%' => $scheme],
        );
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function failure(string $code, string $translationKey, array $parameters = []): SetupStepFailedException
    {
        return SetupStepFailedException::fromMessage(Message::error($code, $translationKey, $parameters));
    }
}
