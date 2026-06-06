<?php

declare(strict_types=1);

namespace App\Setup;

use App\Setup\SetupMessageKey;
use App\View\SystemPackageMetadataProvider;

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
        private readonly SetupSiteSettings $siteSettings = new SetupSiteSettings(),
        private readonly SetupInputNormalizer $inputNormalizer = new SetupInputNormalizer(),
        private readonly SetupInputValidator $inputValidator = new SetupInputValidator(),
        private readonly ?array $extensionAvailability = null,
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
        $siteTitle = $this->prompter->value($options, 'site-title', $this->appName(), $interactive, $language, SetupMessageKey::SETUP_PROMPT_SITE_TITLE);
        $defaultUri = $this->prompter->value($options, 'url', $this->environment('DEFAULT_URI', 'http://localhost'), $interactive, $language, SetupMessageKey::SETUP_PROMPT_DEFAULT_URI);
        $databaseDriver = $this->databaseDriver($options, $databaseUrl, $interactive, $language);
        $parts = $this->databaseParts($options, $databaseUrl, $databaseDriver, $interactive, $language);

        $input = new SetupInput(
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
            databasePrefix: $this->normalizePrefix($this->databasePrefixOption($options)),
            adminUsername: $this->prompter->value($options, 'admin-username', 'admin', $interactive, $language, SetupMessageKey::SETUP_PROMPT_ADMIN_USERNAME),
            adminPassword: $this->prompter->confirmedValue(
                $options,
                'admin-password',
                '',
                $interactive,
                $language,
                SetupMessageKey::SETUP_PROMPT_ADMIN_PASSWORD,
                SetupMessageKey::SETUP_PROMPT_ADMIN_PASSWORD_CONFIRM,
            ),
            adminEmail: $this->prompter->value($options, 'admin-email', $this->inputNormalizer->adminEmailFromDefaultUri($defaultUri), $interactive, $language, SetupMessageKey::SETUP_PROMPT_ADMIN_EMAIL),
            appSecret: $this->prompter->value($options, 'app-secret', '', $interactive, $language, SetupMessageKey::SETUP_PROMPT_APP_SECRET) ?: null,
            siteSettings: $this->siteSettings($options),
            dryRun: array_key_exists('dry-run', $options),
        );
        $this->inputValidator->assertValidInput($input, $this->languageCatalog->availableLanguages($this->projectDir));

        return $input;
    }

    private function appName(): string
    {
        return (new SystemPackageMetadataProvider($this->projectDir))->metadata()['name'];
    }

    /**
     * @param array<string, string|false> $options
     *
     * @return array<string, mixed>
     */
    private function siteSettings(array $options): array
    {
        $values = $this->siteSettings->defaults();

        if (($mode = $this->option($options, 'registration-mode')) !== null) {
            $values['registration_mode'] = $mode;
        }

        foreach ([
            'username-change-enabled' => 'username_change_enabled',
            'statistics-enabled' => 'statistics_enabled',
            'statistics-respect-dnt' => 'statistics_respect_dnt',
        ] as $option => $name) {
            if (array_key_exists($option, $options)) {
                $values[$name] = $this->inputNormalizer->boolValue($options[$option], falseMeansTrue: true);
            }
        }

        return $this->siteSettings->configMap($values);
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

        return $this->prompter->choice($language, SetupMessageKey::SETUP_PROMPT_LANGUAGE, $this->languageCatalog->availableLanguages($this->projectDir), $default);
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
            $url = $this->prompter->value($options, 'database-url', $defaultUrl, $interactive, $language, SetupMessageKey::SETUP_PROMPT_DATABASE_URL);

            return $this->emptyDatabaseParts($url);
        }

        $defaults = $this->serverDatabaseDefaults($databaseUrl, $driver);

        return [
            'database_url' => null,
            'database_host' => $this->prompter->value($options, 'db-host', $defaults['host'], $interactive, $language, SetupMessageKey::SETUP_PROMPT_DATABASE_HOST),
            'database_port' => $this->prompter->value($options, 'db-port', $defaults['port'], $interactive, $language, SetupMessageKey::SETUP_PROMPT_DATABASE_PORT),
            'database_name' => $this->prompter->value($options, 'db-name', $defaults['name'], $interactive, $language, SetupMessageKey::SETUP_PROMPT_DATABASE_NAME),
            'database_user' => $this->prompter->value($options, 'db-user', $defaults['user'], $interactive, $language, SetupMessageKey::SETUP_PROMPT_DATABASE_USER),
            'database_password' => $this->prompter->value($options, 'db-password', $defaults['password'], $interactive, $language, SetupMessageKey::SETUP_PROMPT_DATABASE_PASSWORD),
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
        $availableDrivers = $this->availableDatabaseDrivers();
        if ([] === $availableDrivers) {
            throw new \InvalidArgumentException('No supported database PDO driver is available.');
        }

        $defaultDriver = $this->driverFromDatabaseUrl($databaseUrl);
        $default = $this->databaseDriverAvailable($defaultDriver) ? $defaultDriver->value : $availableDrivers[0]->value;
        $value = $this->option($options, 'db-driver', $default);
        $selectedDriver = $this->inputNormalizer->databaseDriverFromValue($value);

        if (isset($options['database-url']) && !isset($options['db-driver'])) {
            return $this->requireAvailableDatabaseDriver($this->driverFromDatabaseUrl($databaseUrl));
        }

        if (isset($options['database-url']) && isset($options['db-driver']) && null !== $databaseUrl) {
            $urlDriver = $this->inputNormalizer->driverFromDatabaseUrl($databaseUrl);

            if ($selectedDriver !== $urlDriver) {
                throw new \InvalidArgumentException(sprintf(
                    'Database URL scheme "%s" does not match selected database driver "%s".',
                    (string) parse_url($databaseUrl, PHP_URL_SCHEME),
                    $selectedDriver->value,
                ));
            }
        }

        if ($interactive && !isset($options['db-driver'])) {
            $value = $this->prompter->choice(
                $language,
                SetupMessageKey::SETUP_PROMPT_DATABASE_DRIVER,
                array_map(static fn (DatabaseDriver $driver): string => $driver->value, $availableDrivers),
                $default,
            );
            $selectedDriver = $this->inputNormalizer->databaseDriverFromValue($value);
        }

        return $this->requireAvailableDatabaseDriver($selectedDriver);
    }

    /**
     * @return list<DatabaseDriver>
     */
    private function availableDatabaseDrivers(): array
    {
        return array_values(array_filter(
            [DatabaseDriver::SQLite, DatabaseDriver::MySql, DatabaseDriver::PostgreSql],
            $this->databaseDriverAvailable(...),
        ));
    }

    private function requireAvailableDatabaseDriver(DatabaseDriver $driver): DatabaseDriver
    {
        if ($this->databaseDriverAvailable($driver)) {
            return $driver;
        }

        throw new \InvalidArgumentException(sprintf(
            'Database driver "%s" requires PHP extension "%s".',
            $driver->value,
            $this->databaseDriverExtension($driver),
        ));
    }

    private function databaseDriverAvailable(DatabaseDriver $driver): bool
    {
        return $this->extensionLoaded($this->databaseDriverExtension($driver));
    }

    private function databaseDriverExtension(DatabaseDriver $driver): string
    {
        return $this->inputNormalizer->databaseDriverExtension($driver);
    }

    /**
     * @param array<string, string|false> $options
     */
    private function databasePrefixOption(array $options): ?string
    {
        if (array_key_exists('db-prefix', $options)) {
            return is_string($options['db-prefix']) ? $options['db-prefix'] : '';
        }

        return $this->environment('APP_DATABASE_PREFIX');
    }

    private function extensionLoaded(string $extension): bool
    {
        return $this->extensionAvailability[$extension] ?? extension_loaded($extension);
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
        return $this->inputNormalizer->serverDatabaseDefaults($databaseUrl, $driver);
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
        return $this->inputNormalizer->driverFromDatabaseUrl($databaseUrl);
    }

    private function option(array $options, string $name, ?string $default = null): ?string
    {
        $value = $options[$name] ?? null;

        return is_string($value) && '' !== $value ? $value : $default;
    }

    private function normalizePrefix(?string $prefix): ?string
    {
        $prefix = $this->inputNormalizer->normalizeDatabasePrefix((string) $prefix);

        return '' === $prefix ? null : $prefix;
    }

    private function environment(string $key, ?string $default = null): string
    {
        return (string) ($_SERVER[$key] ?? $_ENV[$key] ?? $default);
    }

}
