<?php

declare(strict_types=1);

namespace App\Setup;

use App\View\SystemExtensionMetadataProvider;
use Throwable;

final readonly class SetupWebInputFactory
{
    public const MIN_APP_SECRET_LENGTH = SetupInputValidator::MIN_APP_SECRET_LENGTH;

    public function __construct(
        private string $projectDir,
        private string $environment,
        private SetupLanguageCatalog $languageCatalog = new SetupLanguageCatalog(),
        private SetupSiteSettings $siteSettings = new SetupSiteSettings(),
        private SetupInputNormalizer $inputNormalizer = new SetupInputNormalizer(),
        private SetupInputValidator $inputValidator = new SetupInputValidator(),
        private ?array $extensionAvailability = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        $defaultUri = (string) ($_SERVER['DEFAULT_URI'] ?? $_ENV['DEFAULT_URI'] ?? 'http://localhost');
        $databaseUrl = (string) ($_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? sprintf('sqlite:///%%kernel.project_dir%%/var/data_%s.db', $this->environment));

        return [
            'language' => $this->languageCatalog->defaultLanguage($this->projectDir),
            'site_title' => $this->appName(),
            'default_uri' => '' === trim($defaultUri) ? 'http://localhost' : $defaultUri,
            ...$this->siteSettings->defaults(),
            'database_driver' => $this->defaultDatabaseDriver($databaseUrl),
            'database_url' => $databaseUrl,
            'database_host' => $this->inputNormalizer->databaseUrlPart($databaseUrl, 'host') ?? '127.0.0.1',
            'database_port' => $this->inputNormalizer->databaseUrlPart($databaseUrl, 'port') ?? '',
            'database_name' => $this->inputNormalizer->databaseUrlPathName($databaseUrl) ?? 'app',
            'database_user' => $this->inputNormalizer->databaseUrlPart($databaseUrl, 'user') ?? 'app',
            'database_password' => $this->inputNormalizer->databaseUrlPart($databaseUrl, 'pass') ?? '',
            'database_prefix' => $this->databasePrefixInputValue($this->defaultDatabasePrefix()),
            'admin_username' => 'admin',
            'admin_email' => '',
            'admin_password' => '',
            'admin_password_confirm' => '',
            'app_secret' => '',
            'dry_run' => false,
        ];
    }

    /**
     * @return list<string>
     */
    public function availableLanguages(): array
    {
        return $this->languageCatalog->availableLanguages($this->projectDir);
    }

    /**
     * @return array<string, string>
     */
    public function databaseDriverOptions(): array
    {
        $options = [];
        if ($this->extensionLoaded('pdo_sqlite')) {
            $options[DatabaseDriver::SQLite->value] = 'setup.form.database_driver.options.sqlite';
        }
        if ($this->extensionLoaded('pdo_mysql')) {
            $options[DatabaseDriver::MySql->value] = 'setup.form.database_driver.options.mysql';
        }
        if ($this->extensionLoaded('pdo_pgsql')) {
            $options[DatabaseDriver::PostgreSql->value] = 'setup.form.database_driver.options.postgresql';
        }

        return $options;
    }

    public function databasePrefixInputValue(string $prefix): string
    {
        return $this->inputNormalizer->databasePrefixInputValue($prefix);
    }

    private function defaultDatabasePrefix(): string
    {
        $prefix = (string) ($_SERVER['APP_DATABASE_PREFIX'] ?? $_ENV['APP_DATABASE_PREFIX'] ?? '');

        return '' === trim($prefix) ? 'studio' : $prefix;
    }

    private function defaultDatabaseDriver(string $databaseUrl): string
    {
        $driver = $this->inputNormalizer->driverFromDatabaseUrl($databaseUrl)->value;
        $options = $this->databaseDriverOptions();

        return isset($options[$driver]) ? $driver : (array_key_first($options) ?? DatabaseDriver::SQLite->value);
    }

    private function extensionLoaded(string $extension): bool
    {
        return $this->extensionAvailability[$extension] ?? extension_loaded($extension);
    }

    private function appName(): string
    {
        return (new SystemExtensionMetadataProvider($this->projectDir))->metadata()['name'];
    }

    /**
     * @param array<string, mixed> $submitted
     *
     * @return array<string, mixed>
     */
    public function normalize(array $submitted): array
    {
        return $this->normalizedValues($submitted);
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, list<string>>
     */
    public function validateStep(string $step, array $values): array
    {
        $values = array_replace($this->defaults(), $values);
        $errors = $this->validate($values);
        $fields = match ($step) {
            'language' => ['language'],
            'preflight' => [],
            'site' => ['site_title', 'default_uri', ...array_keys($this->siteSettings->defaults())],
            'database' => ['database_driver', 'database_url', 'database_host', 'database_port', 'database_name', 'database_user', 'database_prefix'],
            'admin' => ['admin_username', 'admin_password', 'admin_password_confirm', 'admin_email', 'app_secret'],
            default => array_keys($values),
        };

        return array_filter(
            $errors,
            static fn (string $field): bool => '__form' === $field || in_array($field, $fields, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @param array<string, mixed> $submitted
     */
    public function create(array $submitted): SetupWebInputResult
    {
        $values = array_replace($this->defaults(), $this->normalizedValues($submitted));
        $errors = $this->validate($values);

        if ([] !== $errors) {
            return new SetupWebInputResult($values, errors: $errors);
        }

        try {
            return new SetupWebInputResult($values, new SetupInput(
                appEnv: $this->environment,
                language: (string) $values['language'],
                siteTitle: (string) $values['site_title'],
                defaultUri: (string) $values['default_uri'],
                databaseDriver: $this->databaseDriver((string) $values['database_driver']),
                databaseUrl: '' === trim((string) $values['database_url']) ? null : (string) $values['database_url'],
                databaseHost: '' === trim((string) $values['database_host']) ? null : (string) $values['database_host'],
                databasePort: '' === trim((string) $values['database_port']) ? null : (int) $values['database_port'],
                databaseName: '' === trim((string) $values['database_name']) ? null : (string) $values['database_name'],
                databaseUser: '' === trim((string) $values['database_user']) ? null : (string) $values['database_user'],
                databasePassword: '' === trim((string) $values['database_password']) ? null : (string) $values['database_password'],
                databasePrefix: '' === trim((string) $values['database_prefix']) ? null : (string) $values['database_prefix'],
                adminUsername: (string) $values['admin_username'],
                adminPassword: (string) $values['admin_password'],
                adminEmail: (string) $values['admin_email'],
                appSecret: '' === trim((string) $values['app_secret']) ? null : (string) $values['app_secret'],
                siteSettings: $this->siteSettings->configMap($values),
                dryRun: true === $values['dry_run'],
            ));
        } catch (Throwable) {
            return new SetupWebInputResult($values, errors: [
                '__form' => ['setup.form.errors.invalid'],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $submitted
     *
     * @return array<string, mixed>
     */
    private function normalizedValues(array $submitted): array
    {
        $values = [];

        foreach (array_keys($this->defaults()) as $key) {
            if ('dry_run' === $key) {
                if (array_key_exists($key, $submitted)) {
                    $values[$key] = $this->boolValue($submitted[$key]);
                }

                continue;
            }

            if (in_array($key, $this->siteSettings->booleanFieldNames(), true)) {
                if (isset($submitted[$key]) && is_scalar($submitted[$key])) {
                    $values[$key] = $this->boolValue($submitted[$key]);
                }

                continue;
            }

            if (isset($submitted[$key]) && is_scalar($submitted[$key])) {
                $values[$key] = 'database_prefix' === $key
                    ? $this->inputNormalizer->normalizeDatabasePrefix((string) $submitted[$key])
                    : trim((string) $submitted[$key]);
            }
        }

        if (isset($values['database_driver']) && DatabaseDriver::SQLite->value !== $values['database_driver']) {
            $values['database_url'] = '';
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, list<string>>
     */
    private function validate(array $values): array
    {
        return $this->inputValidator->validateWebValues($values, $this->availableLanguages(), $this->databaseDriverOptions());
    }

    private function databaseDriver(string $value): DatabaseDriver
    {
        return $this->inputNormalizer->databaseDriverFromFormValue($value);
    }

    private function boolValue(mixed $value): bool
    {
        return $this->inputNormalizer->boolValue($value);
    }
}
