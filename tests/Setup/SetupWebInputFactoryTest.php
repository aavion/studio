<?php

declare(strict_types=1);

namespace App\Tests\Setup;

use App\Setup\SetupWebInputFactory;
use PHPUnit\Framework\TestCase;

final class SetupWebInputFactoryTest extends TestCase
{
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
}
