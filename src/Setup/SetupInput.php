<?php

declare(strict_types=1);

namespace App\Setup;

use InvalidArgumentException;

final readonly class SetupInput
{
    public function __construct(
        private string $appEnv,
        private string $language,
        private string $siteTitle,
        private string $defaultUri,
        private DatabaseDriver $databaseDriver,
        private ?string $databaseUrl = null,
        private ?string $databaseHost = null,
        private ?int $databasePort = null,
        private ?string $databaseName = null,
        private ?string $databaseUser = null,
        private ?string $databasePassword = null,
        private string $adminUsername = 'admin',
        private string $adminPassword = 'admin-password',
        private ?string $adminEmail = null,
        private ?string $appSecret = null,
        private bool $dryRun = false,
    ) {
        if ('' === trim($this->appEnv)) {
            throw new InvalidArgumentException('Setup APP_ENV must not be empty.');
        }

        if (1 !== preg_match('/^[a-z][a-z0-9]*(?:[_-][a-zA-Z0-9]+)*$/', $this->language)) {
            throw new InvalidArgumentException('Setup language must be a valid locale token.');
        }

        if ('' === trim($this->siteTitle)) {
            throw new InvalidArgumentException('Setup site title must not be empty.');
        }

        if ('' === trim($this->defaultUri)) {
            throw new InvalidArgumentException('Setup default URI must not be empty.');
        }
    }

    public static function withDefaults(
        string $appEnv = 'dev',
        string $language = 'en',
        string $defaultUri = 'http://localhost',
        ?string $databaseUrl = null,
    ): self {
        $defaultUri = '' === trim($defaultUri) ? 'http://localhost' : $defaultUri;

        return new self(
            appEnv: $appEnv,
            language: $language,
            siteTitle: 'aavion Studio',
            defaultUri: $defaultUri,
            databaseDriver: self::driverFromDatabaseUrl($databaseUrl),
            databaseUrl: $databaseUrl,
            adminEmail: self::adminEmailFromDefaultUri($defaultUri),
        );
    }

    public function appEnv(): string
    {
        return $this->appEnv;
    }

    public function language(): string
    {
        return $this->language;
    }

    public function siteTitle(): string
    {
        return $this->siteTitle;
    }

    public function defaultUri(): string
    {
        return $this->defaultUri;
    }

    public function databaseDriver(): DatabaseDriver
    {
        return $this->databaseDriver;
    }

    public function databaseUrl(): ?string
    {
        return $this->databaseUrl;
    }

    public function databaseHost(): ?string
    {
        return $this->databaseHost;
    }

    public function databasePort(): ?int
    {
        return $this->databasePort;
    }

    public function databaseName(): ?string
    {
        return $this->databaseName;
    }

    public function databaseUser(): ?string
    {
        return $this->databaseUser;
    }

    public function databasePassword(): ?string
    {
        return $this->databasePassword;
    }

    public function adminUsername(): string
    {
        return $this->adminUsername;
    }

    public function adminPassword(): string
    {
        return $this->adminPassword;
    }

    public function adminEmail(): string
    {
        return $this->adminEmail ?? self::adminEmailFromDefaultUri($this->defaultUri);
    }

    public function appSecret(): ?string
    {
        return $this->appSecret;
    }

    public function dryRun(): bool
    {
        return $this->dryRun;
    }

    private static function driverFromDatabaseUrl(?string $databaseUrl): DatabaseDriver
    {
        if (null === $databaseUrl || '' === trim($databaseUrl)) {
            return DatabaseDriver::SQLite;
        }

        $scheme = (string) parse_url($databaseUrl, PHP_URL_SCHEME);

        return match ($scheme) {
            'mysql', 'mariadb' => DatabaseDriver::MySql,
            'pgsql', 'postgres', 'postgresql' => DatabaseDriver::PostgreSql,
            default => DatabaseDriver::SQLite,
        };
    }

    private static function adminEmailFromDefaultUri(string $defaultUri): string
    {
        $host = parse_url($defaultUri, PHP_URL_HOST);

        if (!is_string($host) || '' === $host) {
            $host = 'localhost';
        }

        return 'admin@'.$host;
    }
}
