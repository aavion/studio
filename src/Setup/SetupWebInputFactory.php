<?php

declare(strict_types=1);

namespace App\Setup;

use Throwable;

final readonly class SetupWebInputFactory
{
    public function __construct(
        private string $projectDir,
        private string $environment,
        private SetupLanguageCatalog $languageCatalog = new SetupLanguageCatalog(),
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
            'database_driver' => $this->driverFromDatabaseUrl($databaseUrl)->value,
            'database_url' => $databaseUrl,
            'admin_username' => 'admin',
            'admin_email' => $this->adminEmailFromDefaultUri($defaultUri),
            'admin_password' => '',
            'admin_password_confirm' => '',
            'app_secret' => '',
            'dry_run' => true,
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
                adminUsername: (string) $values['admin_username'],
                adminPassword: (string) $values['admin_password'],
                adminEmail: (string) $values['admin_email'],
                appSecret: '' === trim((string) $values['app_secret']) ? null : (string) $values['app_secret'],
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
                $values[$key] = isset($submitted[$key]);

                continue;
            }

            if (isset($submitted[$key]) && is_scalar($submitted[$key])) {
                $values[$key] = trim((string) $submitted[$key]);
            }
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

        if (!isset($this->databaseDriverOptions()[(string) $values['database_driver']])) {
            $errors['database_driver'][] = 'setup.form.errors.choice';
        }

        if ((string) $values['admin_password'] !== (string) $values['admin_password_confirm']) {
            $errors['admin_password_confirm'][] = 'setup.form.errors.password_mismatch';
        }

        if (1 !== preg_match('/^[^@\s]+@[^@\s]+$/', (string) $values['admin_email'])) {
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

        return 'admin@'.(is_string($host) && '' !== $host ? $host : 'localhost');
    }
}
