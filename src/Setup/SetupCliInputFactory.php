<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\Message\MessageKey;

final class SetupCliInputFactory
{
    /** @var resource */
    private mixed $input;

    /** @var resource */
    private mixed $output;

    private SetupCliPrompter $prompter;

    public function __construct(
        private readonly string $projectDir,
        private readonly SetupLanguageCatalog $languageCatalog = new SetupLanguageCatalog(),
        SetupMessageTranslator $translator = new SetupMessageTranslator(),
        mixed $input = null,
        mixed $output = null,
        private readonly ?bool $interactive = null,
    ) {
        $this->input = $input ?? STDIN;
        $this->output = $output ?? STDOUT;
        $this->prompter = new SetupCliPrompter($projectDir, $translator, $this->input, $this->output, $interactive);
    }

    /**
     * @param array<string, string|false> $options
     */
    public function create(array $options): SetupInput
    {
        $interactive = $this->prompter->isInteractive($options);
        $databaseUrl = $this->initialDatabaseUrl($options);
        $language = $this->language($options, $interactive);
        $siteTitle = $this->prompter->value($options, 'site-title', 'aavion Studio', $interactive, $language, MessageKey::SETUP_PROMPT_SITE_TITLE);
        $defaultUri = $this->prompter->value($options, 'url', $this->environment('DEFAULT_URI', 'http://localhost'), $interactive, $language, MessageKey::SETUP_PROMPT_DEFAULT_URI);
        $databaseDriver = $this->databaseDriver($options, $databaseUrl, $interactive, $language);
        $parts = $this->databaseParts($options, $databaseUrl, $databaseDriver, $interactive, $language);

        return new SetupInput(
            appEnv: (string) $this->option($options, 'env', $this->environment('APP_ENV', 'dev')),
            language: $language,
            siteTitle: $siteTitle,
            defaultUri: $defaultUri,
            databaseDriver: $databaseDriver,
            databaseUrl: $parts['database_url'],
            databaseHost: $parts['database_host'],
            databasePort: null === $parts['database_port'] ? null : (int) $parts['database_port'],
            databaseName: $parts['database_name'],
            databaseUser: $parts['database_user'],
            databasePassword: $parts['database_password'],
            adminUsername: $this->prompter->value($options, 'admin-username', 'admin', $interactive, $language, MessageKey::SETUP_PROMPT_ADMIN_USERNAME),
            adminPassword: $this->prompter->confirmedValue(
                $options,
                'admin-password',
                '',
                $interactive,
                $language,
                MessageKey::SETUP_PROMPT_ADMIN_PASSWORD,
                MessageKey::SETUP_PROMPT_ADMIN_PASSWORD_CONFIRM,
            ),
            adminEmail: $this->prompter->value($options, 'admin-email', self::adminEmailFromDefaultUri($defaultUri), $interactive, $language, MessageKey::SETUP_PROMPT_ADMIN_EMAIL),
            appSecret: $this->prompter->value($options, 'app-secret', '', $interactive, $language, MessageKey::SETUP_PROMPT_APP_SECRET) ?: null,
            dryRun: array_key_exists('dry-run', $options),
        );
    }

    /**
     * @param array<string, string|false> $options
     */
    private function language(array $options, bool $interactive): string
    {
        $default = $this->languageCatalog->defaultLanguage($this->projectDir);
        $language = $this->option($options, 'language', $default);

        if (!$interactive || isset($options['language'])) {
            return $language;
        }

        return $this->prompter->choice($language, MessageKey::SETUP_PROMPT_LANGUAGE, $this->languageCatalog->availableLanguages($this->projectDir), $default);
    }

    /**
     * @param array<string, string|false> $options
     *
     * @return array{database_url: string|null, database_host: string|null, database_port: string|null, database_name: string|null, database_user: string|null, database_password: string|null}
     */
    private function databaseParts(array $options, ?string $databaseUrl, DatabaseDriver $driver, bool $interactive, string $language): array
    {
        if (null !== $databaseUrl && (!$interactive || isset($options['database-url']))) {
            return $this->emptyDatabaseParts($databaseUrl);
        }

        if (DatabaseDriver::SQLite === $driver) {
            $defaultUrl = $this->sqliteDatabaseUrlDefault($options, $databaseUrl);
            $url = $this->prompter->value($options, 'database-url', $defaultUrl, $interactive, $language, MessageKey::SETUP_PROMPT_DATABASE_URL);

            return $this->emptyDatabaseParts($url);
        }

        $defaults = $this->serverDatabaseDefaults($databaseUrl, $driver);

        return [
            'database_url' => null,
            'database_host' => $this->prompter->value($options, 'db-host', $defaults['host'], $interactive, $language, MessageKey::SETUP_PROMPT_DATABASE_HOST),
            'database_port' => $this->prompter->value($options, 'db-port', $defaults['port'], $interactive, $language, MessageKey::SETUP_PROMPT_DATABASE_PORT),
            'database_name' => $this->prompter->value($options, 'db-name', $defaults['name'], $interactive, $language, MessageKey::SETUP_PROMPT_DATABASE_NAME),
            'database_user' => $this->prompter->value($options, 'db-user', $defaults['user'], $interactive, $language, MessageKey::SETUP_PROMPT_DATABASE_USER),
            'database_password' => $this->prompter->value($options, 'db-password', $defaults['password'], $interactive, $language, MessageKey::SETUP_PROMPT_DATABASE_PASSWORD),
        ];
    }

    /**
     * @return array{database_url: string|null, database_host: null, database_port: null, database_name: null, database_user: null, database_password: null}
     */
    private function emptyDatabaseParts(?string $databaseUrl): array
    {
        return [
            'database_url' => $databaseUrl,
            'database_host' => null,
            'database_port' => null,
            'database_name' => null,
            'database_user' => null,
            'database_password' => null,
        ];
    }

    /**
     * @param array<string, string|false> $options
     */
    private function databaseDriver(array $options, ?string $databaseUrl, bool $interactive, string $language): DatabaseDriver
    {
        $default = $this->driverFromDatabaseUrl($databaseUrl)->value;
        $value = $this->option($options, 'db-driver', $default);

        if (isset($options['database-url']) && !isset($options['db-driver'])) {
            return $this->driverFromDatabaseUrl($databaseUrl);
        }

        if ($interactive && !isset($options['db-driver'])) {
            $value = $this->prompter->choice($language, MessageKey::SETUP_PROMPT_DATABASE_DRIVER, ['sqlite', 'mysql', 'postgresql'], $default);
        }

        return match ($value) {
            'mysql', 'mariadb' => DatabaseDriver::MySql,
            'postgres', 'pgsql', 'postgresql' => DatabaseDriver::PostgreSql,
            'sqlite', null => DatabaseDriver::SQLite,
            default => throw new \InvalidArgumentException(sprintf('Unsupported database driver "%s".', $value)),
        };
    }

    /**
     * @param array<string, string|false> $options
     */
    private function initialDatabaseUrl(array $options): ?string
    {
        if ($this->hasDatabaseConnectionParts($options) && !isset($options['database-url'])) {
            return null;
        }

        $databaseUrl = $this->option($options, 'database-url', $this->environment('DATABASE_URL'));

        return null === $databaseUrl || '' === trim($databaseUrl) ? null : $databaseUrl;
    }

    private function sqliteDatabaseUrlDefault(array $options, ?string $databaseUrl): string
    {
        if (null !== $databaseUrl && DatabaseDriver::SQLite === $this->driverFromDatabaseUrl($databaseUrl)) {
            return $databaseUrl;
        }

        return sprintf('sqlite:///%%kernel.project_dir%%/var/data_%s.db', $this->option($options, 'env', $this->environment('APP_ENV', 'dev')));
    }

    /**
     * @return array{host: string, port: string, name: string, user: string, password: string}
     */
    private function serverDatabaseDefaults(?string $databaseUrl, DatabaseDriver $driver): array
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

    /**
     * @param array<string, string|false> $options
     */
    private function hasDatabaseConnectionParts(array $options): bool
    {
        foreach (['db-host', 'db-port', 'db-name', 'db-user', 'db-password'] as $name) {
            if (isset($options[$name])) {
                return true;
            }
        }

        return false;
    }

    private function driverFromDatabaseUrl(?string $databaseUrl): DatabaseDriver
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

    private function option(array $options, string $name, ?string $default = null): ?string
    {
        $value = $options[$name] ?? null;

        return is_string($value) && '' !== $value ? $value : $default;
    }

    private function environment(string $key, ?string $default = null): string
    {
        return (string) ($_SERVER[$key] ?? $_ENV[$key] ?? $default);
    }

    private static function adminEmailFromDefaultUri(string $defaultUri): string
    {
        $host = parse_url($defaultUri, PHP_URL_HOST);

        return 'admin@'.(is_string($host) && '' !== $host ? $host : 'localhost');
    }
}
