<?php

declare(strict_types=1);

namespace App\Setup;

final readonly class SetupCliDatabaseInput
{
    public function __construct(
        private SetupInputNormalizer $inputNormalizer = new SetupInputNormalizer(),
        private ?array $extensionAvailability = null,
    ) {
    }

    /**
     * @param array<string, string|false> $options
     */
    public function initialDatabaseUrl(array $options, ?string $defaultDatabaseUrl): ?string
    {
        if ($this->hasConnectionParts($options) && !isset($options['database-url'])) {
            return null;
        }

        $databaseUrl = $this->option($options, 'database-url', $defaultDatabaseUrl);

        return null === $databaseUrl || '' === trim($databaseUrl) ? null : $databaseUrl;
    }

    /**
     * @param array<string, string|false> $options
     */
    public function driver(array $options, ?string $databaseUrl, bool $interactive, string $language, SetupCliPrompter $prompter): DatabaseDriver
    {
        $availableDrivers = $this->availableDrivers();
        if ([] === $availableDrivers) {
            throw new \InvalidArgumentException('No supported database PDO driver is available.');
        }

        $defaultDriver = $this->driverFromDatabaseUrl($databaseUrl);
        $default = $this->isDriverAvailable($defaultDriver) ? $defaultDriver->value : $availableDrivers[0]->value;
        $value = $this->option($options, 'db-driver', $default);
        $selectedDriver = $this->inputNormalizer->databaseDriverFromValue($value);

        if (isset($options['database-url']) && !isset($options['db-driver'])) {
            return $this->requireAvailableDriver($this->driverFromDatabaseUrl($databaseUrl));
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
            $value = $prompter->choice(
                $language,
                SetupMessageKey::SETUP_PROMPT_DATABASE_DRIVER,
                array_map(static fn (DatabaseDriver $driver): string => $driver->value, $availableDrivers),
                $default,
            );
            $selectedDriver = $this->inputNormalizer->databaseDriverFromValue($value);
        }

        return $this->requireAvailableDriver($selectedDriver);
    }

    /**
     * @param array<string, string|false> $options
     *
     * @return array{database_url: string|null, database_host: string|null, database_port: string|null, database_name: string|null, database_user: string|null, database_password: string|null}
     */
    public function parts(array $options, ?string $databaseUrl, DatabaseDriver $driver, bool $interactive, string $language, SetupCliPrompter $prompter, string $defaultEnvironment): array
    {
        if (null !== $databaseUrl && (!$interactive || isset($options['database-url']))) {
            return $this->emptyParts($databaseUrl);
        }

        if (DatabaseDriver::SQLite === $driver) {
            $defaultUrl = $this->sqliteUrlDefault($options, $databaseUrl, $defaultEnvironment);
            $url = $prompter->value($options, 'database-url', $defaultUrl, $interactive, $language, SetupMessageKey::SETUP_PROMPT_DATABASE_URL);

            return $this->emptyParts($url);
        }

        $defaults = $this->inputNormalizer->serverDatabaseDefaults($databaseUrl, $driver);

        return [
            'database_url' => null,
            'database_host' => $prompter->value($options, 'db-host', $defaults['host'], $interactive, $language, SetupMessageKey::SETUP_PROMPT_DATABASE_HOST),
            'database_port' => $prompter->value($options, 'db-port', $defaults['port'], $interactive, $language, SetupMessageKey::SETUP_PROMPT_DATABASE_PORT),
            'database_name' => $prompter->value($options, 'db-name', $defaults['name'], $interactive, $language, SetupMessageKey::SETUP_PROMPT_DATABASE_NAME),
            'database_user' => $prompter->value($options, 'db-user', $defaults['user'], $interactive, $language, SetupMessageKey::SETUP_PROMPT_DATABASE_USER),
            'database_password' => $prompter->value($options, 'db-password', $defaults['password'], $interactive, $language, SetupMessageKey::SETUP_PROMPT_DATABASE_PASSWORD),
        ];
    }

    /**
     * @param array<string, string|false> $options
     */
    public function prefix(array $options, ?string $defaultPrefix): ?string
    {
        $prefix = array_key_exists('db-prefix', $options)
            ? (is_string($options['db-prefix']) ? $options['db-prefix'] : '')
            : $defaultPrefix;
        $prefix = $this->inputNormalizer->normalizeDatabasePrefix((string) $prefix);

        return '' === $prefix ? null : $prefix;
    }

    /**
     * @return array{database_url: string|null, database_host: null, database_port: null, database_name: null, database_user: null, database_password: null}
     */
    private function emptyParts(?string $databaseUrl): array
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
    private function sqliteUrlDefault(array $options, ?string $databaseUrl, string $defaultEnvironment): string
    {
        if (null !== $databaseUrl && DatabaseDriver::SQLite === $this->driverFromDatabaseUrl($databaseUrl)) {
            return $databaseUrl;
        }

        return sprintf('sqlite:///%%kernel.project_dir%%/var/data_%s.db', $this->option($options, 'env', $defaultEnvironment));
    }

    /**
     * @return list<DatabaseDriver>
     */
    private function availableDrivers(): array
    {
        return array_values(array_filter(
            [DatabaseDriver::SQLite, DatabaseDriver::MySql, DatabaseDriver::PostgreSql],
            $this->isDriverAvailable(...),
        ));
    }

    private function requireAvailableDriver(DatabaseDriver $driver): DatabaseDriver
    {
        if ($this->isDriverAvailable($driver)) {
            return $driver;
        }

        throw new \InvalidArgumentException(sprintf(
            'Database driver "%s" requires PHP extension "%s".',
            $driver->value,
            $this->inputNormalizer->databaseDriverExtension($driver),
        ));
    }

    private function isDriverAvailable(DatabaseDriver $driver): bool
    {
        $extension = $this->inputNormalizer->databaseDriverExtension($driver);

        return $this->extensionAvailability[$extension] ?? extension_loaded($extension);
    }

    /**
     * @param array<string, string|false> $options
     */
    private function hasConnectionParts(array $options): bool
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

    /**
     * @param array<string, string|false> $options
     */
    private function option(array $options, string $name, ?string $default = null): ?string
    {
        $value = $options[$name] ?? null;

        return is_string($value) && '' !== $value ? $value : $default;
    }
}
