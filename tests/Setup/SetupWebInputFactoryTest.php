<?php

declare(strict_types=1);

namespace App\Tests\Setup;

use App\Setup\SetupWebInputFactory;
use PHPUnit\Framework\TestCase;

final class SetupWebInputFactoryTest extends TestCase
{
    /**
     * @var array<string, array{exists: bool, value: mixed}>
     */
    private array $serverBackup = [];

    /**
     * @var array<string, array{exists: bool, value: mixed}>
     */
    private array $envBackup = [];

    protected function tearDown(): void
    {
        foreach ($this->serverBackup as $name => $backup) {
            if ($backup['exists']) {
                $_SERVER[$name] = $backup['value'];
            } else {
                unset($_SERVER[$name]);
            }
        }

        foreach ($this->envBackup as $name => $backup) {
            if ($backup['exists']) {
                $_ENV[$name] = $backup['value'];
            } else {
                unset($_ENV[$name]);
            }
        }
    }

    public function testItKeepsExplicitFalseDryRunStateWhenCreatingSetupInput(): void
    {
        $factory = new SetupWebInputFactory(dirname(__DIR__, 2), 'test');

        $result = $factory->create([
            'language' => 'en',
            'site_title' => 'Apply Studio',
            'default_uri' => 'http://localhost',
            'registration_mode' => 'disabled',
            'username_change_enabled' => false,
            'statistics_enabled' => true,
            'statistics_respect_dnt' => true,
            'database_driver' => 'sqlite',
            'database_url' => 'sqlite:///%kernel.project_dir%/var/data_test.db',
            'admin_username' => 'admin',
            'admin_password' => 'Safe1!pass',
            'admin_password_confirm' => 'Safe1!pass',
            'admin_email' => 'admin@localhost.local',
            'dry_run' => false,
        ]);

        self::assertTrue($result->isValid());
        self::assertFalse($result->input()?->dryRun());
    }

    public function testItAcceptsTruthyDryRunStateForExplicitDryRuns(): void
    {
        $factory = new SetupWebInputFactory(dirname(__DIR__, 2), 'test');

        $result = $factory->create([
            'language' => 'en',
            'site_title' => 'Dry Studio',
            'default_uri' => 'http://localhost',
            'registration_mode' => 'disabled',
            'username_change_enabled' => false,
            'statistics_enabled' => true,
            'statistics_respect_dnt' => true,
            'database_driver' => 'sqlite',
            'database_url' => 'sqlite:///%kernel.project_dir%/var/data_test.db',
            'admin_username' => 'admin',
            'admin_password' => 'Safe1!pass',
            'admin_password_confirm' => 'Safe1!pass',
            'admin_email' => 'admin@localhost.local',
            'dry_run' => '1',
        ]);

        self::assertTrue($result->isValid());
        self::assertTrue($result->input()?->dryRun());
    }

    public function testItNormalizesDatabasePrefixWithOneSeparatorWhenMissing(): void
    {
        $factory = new SetupWebInputFactory(dirname(__DIR__, 2), 'test');

        $result = $factory->create([
            'language' => 'en',
            'site_title' => 'Prefix Studio',
            'default_uri' => 'http://localhost',
            'registration_mode' => 'disabled',
            'username_change_enabled' => false,
            'statistics_enabled' => true,
            'statistics_respect_dnt' => true,
            'database_driver' => 'mysql',
            'database_host' => '127.0.0.1',
            'database_port' => '3306',
            'database_name' => 'app',
            'database_user' => 'app',
            'database_prefix' => 'studio',
            'admin_username' => 'admin',
            'admin_password' => 'Safe1!pass',
            'admin_password_confirm' => 'Safe1!pass',
            'admin_email' => 'admin@localhost.local',
        ]);

        self::assertTrue($result->isValid());
        self::assertSame('studio_', $result->input()?->databasePrefix());
    }

    public function testItAppendsSeparatorToExplicitTrailingSeparator(): void
    {
        $factory = new SetupWebInputFactory(dirname(__DIR__, 2), 'test');

        $result = $factory->create([
            'language' => 'en',
            'site_title' => 'Prefix Studio',
            'default_uri' => 'http://localhost',
            'registration_mode' => 'disabled',
            'username_change_enabled' => false,
            'statistics_enabled' => true,
            'statistics_respect_dnt' => true,
            'database_driver' => 'mysql',
            'database_host' => '127.0.0.1',
            'database_port' => '3306',
            'database_name' => 'app',
            'database_user' => 'app',
            'database_prefix' => 'studio_',
            'admin_username' => 'admin',
            'admin_password' => 'Safe1!pass',
            'admin_password_confirm' => 'Safe1!pass',
            'admin_email' => 'admin@localhost.local',
        ]);

        self::assertTrue($result->isValid());
        self::assertSame('studio__', $result->input()?->databasePrefix());
    }

    public function testItKeepsEmptyDatabasePrefixEmpty(): void
    {
        $factory = new SetupWebInputFactory(dirname(__DIR__, 2), 'test');

        $result = $factory->create([
            'language' => 'en',
            'site_title' => 'Prefix Studio',
            'default_uri' => 'http://localhost',
            'registration_mode' => 'disabled',
            'username_change_enabled' => false,
            'statistics_enabled' => true,
            'statistics_respect_dnt' => true,
            'database_driver' => 'mysql',
            'database_host' => '127.0.0.1',
            'database_port' => '3306',
            'database_name' => 'app',
            'database_user' => 'app',
            'database_prefix' => '',
            'admin_username' => 'admin',
            'admin_password' => 'Safe1!pass',
            'admin_password_confirm' => 'Safe1!pass',
            'admin_email' => 'admin@localhost.local',
        ]);

        self::assertTrue($result->isValid());
        self::assertNull($result->input()?->databasePrefix());
    }

    public function testItFormatsStoredDatabasePrefixForDisplay(): void
    {
        $factory = new SetupWebInputFactory(dirname(__DIR__, 2), 'test');

        self::assertSame('studio', $factory->databasePrefixInputValue('studio_'));
        self::assertSame('studio_', $factory->databasePrefixInputValue('studio__'));
        self::assertSame('', $factory->databasePrefixInputValue(''));
    }

    public function testItDefaultsEmptyEnvironmentDatabasePrefixToStudioInput(): void
    {
        $this->setEnvironmentValue('APP_DATABASE_PREFIX', '');

        $factory = new SetupWebInputFactory(dirname(__DIR__, 2), 'test');

        self::assertSame('studio', $factory->defaults()['database_prefix']);
    }

    public function testItRejectsInvalidDatabaseUrl(): void
    {
        $factory = new SetupWebInputFactory(dirname(__DIR__, 2), 'test');

        $result = $factory->create([
            'language' => 'en',
            'site_title' => 'Broken DB Studio',
            'default_uri' => 'http://localhost',
            'registration_mode' => 'disabled',
            'username_change_enabled' => false,
            'statistics_enabled' => true,
            'statistics_respect_dnt' => true,
            'database_driver' => 'sqlite',
            'database_url' => 'not-a-database-url',
            'admin_username' => 'admin',
            'admin_password' => 'Safe1!pass',
            'admin_password_confirm' => 'Safe1!pass',
            'admin_email' => 'admin@localhost.local',
        ]);

        self::assertFalse($result->isValid());
        self::assertSame(['setup.form.errors.database_url'], $result->errors()['database_url']);
    }

    private function setEnvironmentValue(string $name, string $value): void
    {
        if (!array_key_exists($name, $this->serverBackup)) {
            $this->serverBackup[$name] = [
                'exists' => array_key_exists($name, $_SERVER),
                'value' => $_SERVER[$name] ?? null,
            ];
        }

        if (!array_key_exists($name, $this->envBackup)) {
            $this->envBackup[$name] = [
                'exists' => array_key_exists($name, $_ENV),
                'value' => $_ENV[$name] ?? null,
            ];
        }

        $_SERVER[$name] = $value;
        $_ENV[$name] = $value;
    }
}
