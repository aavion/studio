<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\Message\MessageException;
use App\Core\Validation\EmailAddress;
use App\Core\Validation\IdentifierSpec;
use App\Entity\UserAccount;
use App\Security\PasswordPolicy;

final readonly class SetupInputValidator
{
    public const MIN_APP_SECRET_LENGTH = 32;

    public function __construct(
        private SetupPasswordPolicy $passwordPolicy = new SetupPasswordPolicy(),
        private SetupInputNormalizer $inputNormalizer = new SetupInputNormalizer(),
    ) {
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $availableLanguages
     * @param array<string, string> $databaseDriverOptions
     *
     * @return array<string, list<string>>
     */
    public function validateWebValues(array $values, array $availableLanguages, array $databaseDriverOptions): array
    {
        $errors = [];

        foreach (['language', 'site_title', 'default_uri', 'database_driver', 'admin_username', 'admin_password', 'admin_password_confirm', 'admin_email'] as $required) {
            if ('' === trim((string) ($values[$required] ?? ''))) {
                $errors[$required][] = 'setup.form.errors.required';
            }
        }

        if (!in_array((string) $values['language'], $availableLanguages, true)) {
            $errors['language'][] = 'setup.form.errors.choice';
        }

        if (!in_array((string) $values['registration_mode'], ['disabled', 'admin_approval', 'auto_approval'], true)) {
            $errors['registration_mode'][] = 'setup.form.errors.choice';
        }

        if (false === filter_var((string) $values['default_uri'], FILTER_VALIDATE_URL)) {
            $errors['default_uri'][] = 'setup.form.errors.url';
        }

        if (!isset($databaseDriverOptions[(string) $values['database_driver']])) {
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

        if ('' !== trim((string) $values['database_url']) && !$this->inputNormalizer->isValidDatabaseUrl((string) $values['database_url'], $driver)) {
            $errors['database_url'][] = 'setup.form.errors.database_url';
        }

        if ('' !== trim((string) $values['database_prefix']) && !IdentifierSpec::isDatabasePrefix((string) $values['database_prefix'])) {
            $errors['database_prefix'][] = 'setup.form.errors.database_prefix';
        }

        if ((string) $values['admin_password'] !== (string) $values['admin_password_confirm']) {
            $errors['admin_password_confirm'][] = 'setup.form.errors.password_mismatch';
        }

        if (!UserAccount::isValidUsername((string) $values['admin_username'])) {
            $errors['admin_username'][] = 'setup.form.errors.username';
        }

        $adminPassword = (string) $values['admin_password'];

        if ('' !== trim($adminPassword)) {
            foreach ($this->passwordPolicy->violationCodes($adminPassword, (string) $values['admin_username'], (string) $values['admin_email']) as $violation) {
                $errors['admin_password'][] = match ($violation) {
                    PasswordPolicy::VIOLATION_COMPLEXITY => 'setup.form.errors.password_complexity',
                    PasswordPolicy::VIOLATION_REPEATED => 'setup.form.errors.password_repeated',
                    PasswordPolicy::VIOLATION_PERSONAL => 'setup.form.errors.password_personal',
                    default => 'setup.form.errors.password_length',
                };
            }
        }

        if (!EmailAddress::isValid((string) $values['admin_email'])) {
            $errors['admin_email'][] = 'setup.form.errors.email';
        }

        if ('' !== trim((string) $values['app_secret']) && strlen((string) $values['app_secret']) < self::MIN_APP_SECRET_LENGTH) {
            $errors['app_secret'][] = 'setup.form.errors.app_secret_length';
        }

        return $errors;
    }

    /**
     * @param list<string> $availableLanguages
     */
    public function assertValidInput(SetupInput $input, array $availableLanguages): void
    {
        if (!in_array($input->language(), $availableLanguages, true)) {
            throw MessageException::invalidArgument(SetupMessageKey::SETUP_INPUT_LANGUAGE_UNAVAILABLE, [
                '%language%' => $input->language(),
            ]);
        }

        if (false === filter_var($input->defaultUri(), FILTER_VALIDATE_URL)) {
            throw MessageException::invalidArgument(SetupMessageKey::SETUP_INPUT_DEFAULT_URI_INVALID, [
                '%uri%' => $input->defaultUri(),
            ]);
        }

        if (null !== $input->databaseUrl() && !$this->inputNormalizer->isValidDatabaseUrl($input->databaseUrl(), $input->databaseDriver())) {
            throw MessageException::invalidArgument(SetupMessageKey::SETUP_INPUT_DATABASE_URL_DRIVER_MISMATCH, [
                '%driver%' => $input->databaseDriver()->value,
            ]);
        }

        if (null !== $input->appSecret() && strlen($input->appSecret()) < self::MIN_APP_SECRET_LENGTH) {
            throw MessageException::invalidArgument(SetupMessageKey::SETUP_APP_SECRET_TOO_SHORT, [
                '%min_length%' => self::MIN_APP_SECRET_LENGTH,
            ]);
        }
    }

    private function databaseDriver(string $value): DatabaseDriver
    {
        return $this->inputNormalizer->databaseDriverFromFormValue($value);
    }
}
