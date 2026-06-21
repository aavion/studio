<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\Message\MessageException;
use App\Core\Validation\EmailAddress;
use App\Core\Validation\IdentifierSpec;
use App\Entity\UserAccount;
use App\Localization\LocaleToken;

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
        private ?string $databasePrefix = null,
        private string $adminUsername = 'admin',
        private string $adminPassword = 'Safe1!pass',
        private ?string $adminEmail = null,
        private ?string $appSecret = null,
        /** @var array<string, mixed> */
        private array $siteSettings = [],
        private bool $dryRun = false,
    ) {
        if ('' === trim($this->appEnv)) {
            throw MessageException::invalidArgument(SetupMessageKey::SETUP_INPUT_APP_ENV_EMPTY);
        }

        if (1 !== preg_match('/^[a-z][a-z0-9]*(?:[_-][a-zA-Z0-9]+)*$/', $this->language)) {
            throw MessageException::invalidArgument(SetupMessageKey::SETUP_INPUT_LANGUAGE_INVALID, [
                '%language%' => $this->language,
            ]);
        }

        if ('' === trim($this->siteTitle)) {
            throw MessageException::invalidArgument(SetupMessageKey::SETUP_INPUT_SITE_TITLE_EMPTY);
        }

        if ('' === trim($this->defaultUri)) {
            throw MessageException::invalidArgument(SetupMessageKey::SETUP_INPUT_DEFAULT_URI_EMPTY);
        }

        if (!UserAccount::isValidUsername($this->adminUsername)) {
            throw MessageException::invalidArgument(SetupMessageKey::SETUP_INPUT_ADMIN_USERNAME_INVALID, [
                '%username%' => $this->adminUsername,
            ]);
        }

        if (null !== $this->adminEmail && !EmailAddress::isValid($this->adminEmail)) {
            throw MessageException::invalidArgument(SetupMessageKey::SETUP_INPUT_ADMIN_EMAIL_INVALID, [
                '%email%' => $this->adminEmail,
            ]);
        }

        if (null !== $this->databasePrefix && '' !== $this->databasePrefix && !IdentifierSpec::isDatabasePrefix($this->databasePrefix)) {
            throw MessageException::invalidArgument(SetupMessageKey::SETUP_INPUT_DATABASE_PREFIX_INVALID, [
                '%prefix%' => $this->databasePrefix,
            ]);
        }
    }

    public static function withDefaults(
        string $appEnv = 'dev',
        ?string $language = null,
        string $defaultUri = 'http://localhost',
        ?string $databaseUrl = null,
    ): self {
        $defaultUri = '' === trim($defaultUri) ? 'http://localhost' : $defaultUri;
        $language ??= LocaleToken::systemDefault();

        return new self(
            appEnv: $appEnv,
            language: $language,
            siteTitle: 'Studio',
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

    public function databasePrefix(): ?string
    {
        return $this->databasePrefix;
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
        return EmailAddress::assert($this->adminEmail ?? self::adminEmailFromDefaultUri($this->defaultUri));
    }

    public function appSecret(): ?string
    {
        return $this->appSecret;
    }

    /**
     * @return array<string, mixed>
     */
    public function siteSettings(): array
    {
        return $this->siteSettings;
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

        if (!is_string($host) || '' === $host || !EmailAddress::isValid('admin@'.$host)) {
            $host = 'localhost.local';
        }

        return EmailAddress::normalize('admin@'.$host);
    }
}
