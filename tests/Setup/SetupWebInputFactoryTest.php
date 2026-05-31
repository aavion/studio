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
            'admin_password' => 'admin-password',
            'admin_password_confirm' => 'admin-password',
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
            'admin_password' => 'admin-password',
            'admin_password_confirm' => 'admin-password',
            'admin_email' => 'admin@localhost.local',
            'dry_run' => '1',
        ]);

        self::assertTrue($result->isValid());
        self::assertTrue($result->input()?->dryRun());
    }
}
