<?php

declare(strict_types=1);

namespace App\Tests\Setup;

use App\Setup\DatabaseDriver;
use App\Setup\SetupInput;
use App\Setup\SetupInputValidator;
use PHPUnit\Framework\TestCase;

final class SetupInputValidatorTest extends TestCase
{
    public function testItReturnsWebFormErrorKeys(): void
    {
        $errors = (new SetupInputValidator())->validateWebValues([
            'language' => 'fr',
            'site_title' => '',
            'default_uri' => 'not-a-url',
            'registration_mode' => 'unknown',
            'database_driver' => 'mysql',
            'database_url' => '',
            'database_host' => '',
            'database_port' => '99999',
            'database_name' => '',
            'database_user' => '',
            'database_prefix' => 'InvalidPrefix',
            'admin_username' => 'bad.name',
            'admin_password' => 'short',
            'admin_password_confirm' => 'different',
            'admin_email' => 'not-an-email',
            'app_secret' => 'short',
        ], ['en', 'de'], [
            'sqlite' => 'setup.form.database_driver.options.sqlite',
            'mysql' => 'setup.form.database_driver.options.mysql',
        ]);

        self::assertSame(['setup.form.errors.required'], $errors['site_title']);
        self::assertSame(['setup.form.errors.choice'], $errors['language']);
        self::assertSame(['setup.form.errors.url'], $errors['default_uri']);
        self::assertSame(['setup.form.errors.database_prefix'], $errors['database_prefix']);
        self::assertSame(['setup.form.errors.app_secret_length'], $errors['app_secret']);
    }

    public function testItRejectsCliInputsWithUnavailableLanguage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Setup language "fr" is not available.');

        (new SetupInputValidator())->assertValidInput($this->input(language: 'fr'), ['en', 'de']);
    }

    public function testItRejectsCliInputsWithShortAppSecret(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('APP_SECRET must be at least');

        (new SetupInputValidator())->assertValidInput($this->input(appSecret: 'short'), ['en']);
    }

    private function input(string $language = 'en', ?string $appSecret = 'app-secret-12'): SetupInput
    {
        return new SetupInput(
            appEnv: 'test',
            language: $language,
            siteTitle: 'Validator Studio',
            defaultUri: 'https://example.test',
            databaseDriver: DatabaseDriver::SQLite,
            databaseUrl: 'sqlite:///%kernel.project_dir%/var/test.db',
            adminUsername: 'admin',
            adminPassword: 'Safe1!pass',
            adminEmail: 'admin@example.test',
            appSecret: $appSecret,
        );
    }
}
