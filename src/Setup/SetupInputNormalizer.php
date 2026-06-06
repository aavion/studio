<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\Validation\EmailAddress;

final readonly class SetupInputNormalizer
{
    public function databaseDriverFromValue(?string $value): DatabaseDriver
    {
        return match ($value) {
            'mysql', 'mariadb' => DatabaseDriver::MySql,
            'postgres', 'pgsql', 'postgresql' => DatabaseDriver::PostgreSql,
            'sqlite', null => DatabaseDriver::SQLite,
            default => throw new \InvalidArgumentException(sprintf('Unsupported database driver "%s".', $value)),
        };
    }

    public function databaseDriverFromFormValue(string $value): DatabaseDriver
    {
        try {
            return $this->databaseDriverFromValue($value);
        } catch (\InvalidArgumentException) {
            return DatabaseDriver::SQLite;
        }
    }

    public function driverFromDatabaseUrl(?string $databaseUrl): DatabaseDriver
    {
        if (null === $databaseUrl || '' === trim($databaseUrl)) {
            return DatabaseDriver::SQLite;
        }

        return match ((string) parse_url($databaseUrl, PHP_URL_SCHEME)) {
            'mysql', 'mariadb' => DatabaseDriver::MySql,
            'pgsql', 'postgres', 'postgresql' => DatabaseDriver::PostgreSql,
            default => DatabaseDriver::SQLite,
        };
    }

    public function databaseDriverExtension(DatabaseDriver $driver): string
    {
        return match ($driver) {
            DatabaseDriver::SQLite => 'pdo_sqlite',
            DatabaseDriver::MySql => 'pdo_mysql',
            DatabaseDriver::PostgreSql => 'pdo_pgsql',
        };
    }

    /**
     * @return array{host: string, port: string, name: string, user: string, password: string}
     */
    public function serverDatabaseDefaults(?string $databaseUrl, DatabaseDriver $driver): array
    {
        $defaults = [
            'host' => '127.0.0.1',
            'port' => DatabaseDriver::MySql === $driver ? '3306' : '5432',
            'name' => 'studio',
            'user' => 'studio',
            'password' => '',
        ];

        if (null === $databaseUrl || $driver !== $this->driverFromDatabaseUrl($databaseUrl)) {
            return $defaults;
        }

        return [
            'host' => (string) (parse_url($databaseUrl, PHP_URL_HOST) ?: $defaults['host']),
            'port' => (string) (parse_url($databaseUrl, PHP_URL_PORT) ?: $defaults['port']),
            'name' => urldecode(trim((string) parse_url($databaseUrl, PHP_URL_PATH), '/')) ?: $defaults['name'],
            'user' => urldecode((string) (parse_url($databaseUrl, PHP_URL_USER) ?: $defaults['user'])),
            'password' => urldecode((string) (parse_url($databaseUrl, PHP_URL_PASS) ?: $defaults['password'])),
        ];
    }

    public function normalizeDatabasePrefix(string $prefix): string
    {
        $prefix = trim($prefix);

        return '' === $prefix ? '' : rtrim($prefix, '_').'_';
    }

    public function databasePrefixInputValue(string $prefix): string
    {
        return str_ends_with($prefix, '_') ? substr($prefix, 0, -1) : $prefix;
    }

    public function boolValue(mixed $value, bool $falseMeansTrue = false): bool
    {
        if (false === $value && $falseMeansTrue) {
            return true;
        }

        if (is_bool($value)) {
            return $value;
        }

        return is_scalar($value) && in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on', 'enabled'], true);
    }

    public function isValidDatabaseUrl(string $databaseUrl, DatabaseDriver $driver): bool
    {
        if (DatabaseDriver::SQLite === $driver) {
            return str_starts_with($databaseUrl, 'sqlite:///')
                && '' !== trim((string) preg_replace('#^sqlite:///#', '', $databaseUrl));
        }

        $scheme = parse_url($databaseUrl, PHP_URL_SCHEME);
        $host = parse_url($databaseUrl, PHP_URL_HOST);
        $allowedSchemes = DatabaseDriver::MySql === $driver
            ? ['mysql', 'mariadb']
            : ['pgsql', 'postgres', 'postgresql'];

        return is_string($scheme)
            && in_array($scheme, $allowedSchemes, true)
            && is_string($host)
            && '' !== trim($host);
    }

    public function databaseUrlPart(string $databaseUrl, string $part): ?string
    {
        $value = parse_url($databaseUrl, match ($part) {
            'host' => PHP_URL_HOST,
            'port' => PHP_URL_PORT,
            'user' => PHP_URL_USER,
            'pass' => PHP_URL_PASS,
            default => -1,
        });

        return is_scalar($value) ? (string) $value : null;
    }

    public function databaseUrlPathName(string $databaseUrl): ?string
    {
        $path = parse_url($databaseUrl, PHP_URL_PATH);

        if (!is_string($path) || '' === trim($path, '/')) {
            return null;
        }

        return rawurldecode(trim($path, '/'));
    }

    public function adminEmailFromDefaultUri(string $defaultUri): string
    {
        $host = parse_url($defaultUri, PHP_URL_HOST);

        if (!is_string($host) || '' === $host || !EmailAddress::isValid('admin@'.$host)) {
            $host = 'localhost.local';
        }

        return EmailAddress::normalize('admin@'.$host);
    }
}
