<?php

declare(strict_types=1);

namespace App\Tests\Setup;

use App\Setup\DatabaseDriver;
use App\Setup\SetupDefaultSeed;
use App\Setup\SetupInput;
use App\Security\UserFlowConfig;
use PHPUnit\Framework\TestCase;

final class SetupDefaultSeedTest extends TestCase
{
    public function testItBuildsInputAwareConfigDefaults(): void
    {
        $input = $this->input(siteTitle: 'Seeded Studio', defaultUri: 'https://seed.example.test', language: 'de');
        $settings = (new SetupDefaultSeed())->configMap($input);

        self::assertSame('Seeded Studio', $settings['site.title']);
        self::assertSame('https://seed.example.test', $settings['site.url']);
        self::assertSame('de', $settings['localization.default_language']);
        self::assertFalse($settings[UserFlowConfig::USERNAME_CHANGE_ENABLED_KEY]);
        self::assertSame(UserFlowConfig::DEFAULT_ACCOUNT_LINK_TTL_HOURS, $settings[UserFlowConfig::ACCOUNT_LINK_TTL_HOURS_KEY]);
        self::assertSame(UserFlowConfig::DEFAULT_DELETED_USER_RETENTION_DAYS, $settings[UserFlowConfig::DELETED_USER_RETENTION_DAYS_KEY]);
    }

    public function testItUsesValidLocalhostAdminEmailDefault(): void
    {
        self::assertSame('admin@localhost.local', SetupInput::withDefaults(defaultUri: 'http://localhost')->adminEmail());
    }

    public function testItDefinesSetupAclAndContentDefaults(): void
    {
        $seed = new SetupDefaultSeed();
        $input = $this->input(siteTitle: 'Seeded Studio');

        self::assertSame([], $seed->aclGroups());
        self::assertSame('/home', $seed->homePath());
        self::assertSame('static_page', $seed->contentSchema()['identifier']);
        self::assertSame(['title', 'subtitle', 'body', 'seo_title'], array_column($seed->contentSchemaVersion()['definition']['fields'], 'identifier'));
        self::assertSame('home', $seed->homeContentItem()['slug']);
        self::assertSame('Seeded Studio', $seed->homeContentFields($input)['title']['en']);
    }

    private function input(
        string $siteTitle = 'Test Studio',
        string $defaultUri = 'https://example.test',
        string $language = 'en',
    ): SetupInput {
        return new SetupInput(
            appEnv: 'test',
            language: $language,
            siteTitle: $siteTitle,
            defaultUri: $defaultUri,
            databaseDriver: DatabaseDriver::SQLite,
            databaseUrl: 'sqlite:///%kernel.project_dir%/var/test/test.db',
            adminUsername: 'admin',
            adminPassword: 'secret-password',
            adminEmail: 'admin@example.test',
        );
    }
}
