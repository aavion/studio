<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\Validation\EmailAddress;
use App\Entity\UserAccount;
use Throwable;

final readonly class SetupWebInputFactory
{
    public function __construct(
        private string $projectDir,
        private string $environment,
        private SetupLanguageCatalog $languageCatalog = new SetupLanguageCatalog(),
        private SetupPasswordPolicy $passwordPolicy = new SetupPasswordPolicy(),
        private SetupSiteSettings $siteSettings = new SetupSiteSettings(),
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
            'site_title' => 'aavion Studio',
            'default_uri' => '' === trim($defaultUri) ? 'http://localhost' : $defaultUri,
            ...$this->siteSettings->defaults(),
            'database_driver' => $this->driverFromDatabaseUrl($databaseUrl)->value,
            'database_url' => $databaseUrl,
            'database_host' => $this->databaseUrlPart($databaseUrl, 'host') ?? '127.0.0.1',
            'database_port' => $this->databaseUrlPart($databaseUrl, 'port') ?? '',
            'database_name' => $this->databaseUrlPathName($databaseUrl) ?? 'studio',
            'database_user' => $this->databaseUrlPart($databaseUrl, 'user') ?? 'studio',
            'database_password' => $this->databaseUrlPart($databaseUrl, 'pass') ?? '',
            'database_prefix' => (string) ($_SERVER['APP_DATABASE_PREFIX'] ?? $_ENV['APP_DATABASE_PREFIX'] ?? ''),
            'admin_username' => 'admin',
            'admin_email' => $this->adminEmailFromDefaultUri($defaultUri),
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
        return [
            DatabaseDriver::SQLite->value => 'setup.form.database_driver.options.sqlite',
            DatabaseDriver::MySql->value => 'setup.form.database_driver.options.mysql',
            DatabaseDriver::PostgreSql->value => 'setup.form.database_driver.options.postgresql',
        ];
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
                $values[$key] = trim((string) $submitted[$key]);
            }
        }

        if (isset($values['database_driver']) && DatabaseDriver::SQLite->value === $values['database_driver']) {
            $values['database_prefix'] = '';
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
        $errors = [];

        foreach (['language', 'site_title', 'default_uri', 'database_driver', 'admin_username', 'admin_password', 'admin_password_confirm', 'admin_email'] as $required) {
            if ('' === trim((string) ($values[$required] ?? ''))) {
                $errors[$required][] = 'setup.form.errors.required';
            }
        }

        if (!in_array((string) $values['language'], $this->availableLanguages(), true)) {
            $errors['language'][] = 'setup.form.errors.choice';
        }

        if (!in_array((string) $values['registration_mode'], ['disabled', 'admin_approval', 'auto_approval'], true)) {
            $errors['registration_mode'][] = 'setup.form.errors.choice';
        }

        if (!isset($this->databaseDriverOptions()[(string) $values['database_driver']])) {
            $errors['database_driver'][] = 'setup.form.errors.choice';
        }

        $driver = $this->databaseDriver((string) $values['database_driver']);

        if (DatabaseDriver::SQLite !== $driver && '' === trim((string) $values['database_url'])) {
            foreach (['database_host', 'database_port', 'database_name', 'database_user'] as $required) {
                if ('' === trim((string) ($values[$required] ?? ''))) {
                    $errors[$required][] = 'setup.form.errors.required';
                }
            }
        }

        if ('' !== trim((string) $values['database_port']) && false === filter_var((string) $values['database_port'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]])) {
            $errors['database_port'][] = 'setup.form.errors.port';
        }

        if ('' !== trim((string) $values['database_prefix']) && 1 !== preg_match('/^[a-z][a-z0-9_]*_$/', (string) $values['database_prefix'])) {
            $errors['database_prefix'][] = 'setup.form.errors.database_prefix';
        }

        if ((string) $values['admin_password'] !== (string) $values['admin_password_confirm']) {
            $errors['admin_password_confirm'][] = 'setup.form.errors.password_mismatch';
        }

        if (!UserAccount::isValidUsername((string) $values['admin_username'])) {
            $errors['admin_username'][] = 'setup.form.errors.username';
        }

        $adminPassword = (string) $values['admin_password'];

        if ('' !== trim($adminPassword) && !$this->passwordPolicy->isValidAdminPassword($adminPassword)) {
            $errors['admin_password'][] = 'setup.form.errors.password_length';
        }

        if (!EmailAddress::isValid((string) $values['admin_email'])) {
            $errors['admin_email'][] = 'setup.form.errors.email';
        }

        return $errors;
    }

    private function databaseDriver(string $value): DatabaseDriver
    {
        return match ($value) {
            DatabaseDriver::MySql->value => DatabaseDriver::MySql,
            DatabaseDriver::PostgreSql->value => DatabaseDriver::PostgreSql,
            default => DatabaseDriver::SQLite,
        };
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return is_scalar($value) && in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    private function driverFromDatabaseUrl(string $databaseUrl): DatabaseDriver
    {
        return match ((string) parse_url($databaseUrl, PHP_URL_SCHEME)) {
            'mysql', 'mariadb' => DatabaseDriver::MySql,
            'pgsql', 'postgres', 'postgresql' => DatabaseDriver::PostgreSql,
            default => DatabaseDriver::SQLite,
        };
    }

    private function adminEmailFromDefaultUri(string $defaultUri): string
    {
        $host = parse_url($defaultUri, PHP_URL_HOST);

        if (!is_string($host) || '' === $host || !EmailAddress::isValid('admin@'.$host)) {
            $host = 'localhost.local';
        }

        return EmailAddress::normalize('admin@'.$host);
    }

    private function databaseUrlPart(string $databaseUrl, string $part): ?string
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

    private function databaseUrlPathName(string $databaseUrl): ?string
    {
        $path = parse_url($databaseUrl, PHP_URL_PATH);

        if (!is_string($path) || '' === trim($path, '/')) {
            return null;
        }

        return rawurldecode(trim($path, '/'));
    }
}
