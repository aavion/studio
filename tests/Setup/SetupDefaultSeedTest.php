<?php

declare(strict_types=1);

namespace App\Tests\Setup;

use App\Api\ApiFeaturePolicy;
use App\Core\Config\ConfigDefaultProviderInterface;
use App\Core\Geo\MaxMindGeoIpConfig;
use App\Core\Log\DatabaseLogRetentionPolicy;
use App\Setup\DatabaseDriver;
use App\Setup\SetupDefaultSeed;
use App\Setup\SetupInput;
use App\Scheduler\SchedulerSettings;
use App\Security\Abuse\SuspiciousProbePathMatcher;
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
        self::assertTrue($settings[ApiFeaturePolicy::ENABLED_KEY]);
        self::assertFalse($settings[ApiFeaturePolicy::CORS_ENABLED_KEY]);
        self::assertSame([], $settings[ApiFeaturePolicy::CORS_ALLOWED_ORIGINS_KEY]);
        self::assertFalse($settings[MaxMindGeoIpConfig::ENABLED_KEY]);
        self::assertSame(MaxMindGeoIpConfig::DEFAULT_DATABASE_PATH, $settings[MaxMindGeoIpConfig::DATABASE_PATH_KEY]);
        self::assertSame('', $settings[MaxMindGeoIpConfig::LICENSE_KEY_KEY]);
        self::assertSame(DatabaseLogRetentionPolicy::DEFAULT_LOG_RETENTION_DAYS, $settings[DatabaseLogRetentionPolicy::ACCESS_LOG_RETENTION_DAYS_KEY]);
        self::assertSame(DatabaseLogRetentionPolicy::DEFAULT_SECURITY_SIGNAL_RETENTION_DAYS, $settings[DatabaseLogRetentionPolicy::SECURITY_SIGNAL_RETENTION_DAYS_KEY]);
        self::assertSame(DatabaseLogRetentionPolicy::DEFAULT_SECURITY_SIGNAL_IP_RETENTION_DAYS, $settings[DatabaseLogRetentionPolicy::SECURITY_SIGNAL_IP_RETENTION_DAYS_KEY]);
        self::assertSame(SuspiciousProbePathMatcher::defaultPatternText(), $settings[SuspiciousProbePathMatcher::PATTERNS_KEY]);
    }

    public function testItUsesCentralConfigDefaultsForSetupSeededSettings(): void
    {
        $seed = new SetupDefaultSeed(new SetupSeedConfigDefaultProvider([
            'content.home_path' => '/start',
            UserFlowConfig::ACCOUNT_LINK_TTL_HOURS_KEY => 48,
            SchedulerSettings::ENABLED_KEY => false,
        ]));
        $settings = $seed->configMap($this->input(siteSettings: [
            SchedulerSettings::ENABLED_KEY => true,
        ]));

        self::assertSame('/start', $settings['content.home_path']);
        self::assertSame(48, $settings[UserFlowConfig::ACCOUNT_LINK_TTL_HOURS_KEY]);
        self::assertTrue($settings[SchedulerSettings::ENABLED_KEY]);
    }

    public function testEverySetupConfigKeyHasACentralDefaultExceptSetupInputValues(): void
    {
        $defaultKeys = [
            'content.home_path',
            'localization.route_prefixes_enabled',
            'site.footer_copyright',
            UserFlowConfig::DEFAULT_ACL_GROUP_KEY,
            UserFlowConfig::USERNAME_CHANGE_ENABLED_KEY,
            UserFlowConfig::ACCOUNT_LINK_TTL_HOURS_KEY,
            UserFlowConfig::REGISTRATION_ADMIN_NOTIFICATION_EMAIL_KEY,
            UserFlowConfig::SECURITY_NOTIFICATION_EMAIL_KEY,
            UserFlowConfig::DELETED_USER_RETENTION_DAYS_KEY,
            'user.menu.enabled',
            'user.menu.sort_order',
            UserFlowConfig::REGISTRATION_MODE_KEY,
            \App\Core\Log\ConfigAuditLogPolicy::ENABLED_KEY,
            \App\Core\Log\ConfigAuditLogPolicy::EVENTS_KEY,
            DatabaseLogRetentionPolicy::MESSAGE_LOG_RETENTION_DAYS_KEY,
            DatabaseLogRetentionPolicy::AUDIT_LOG_RETENTION_DAYS_KEY,
            DatabaseLogRetentionPolicy::ACCESS_LOG_RETENTION_DAYS_KEY,
            DatabaseLogRetentionPolicy::SECURITY_SIGNAL_RETENTION_DAYS_KEY,
            DatabaseLogRetentionPolicy::SECURITY_SIGNAL_IP_RETENTION_DAYS_KEY,
            SuspiciousProbePathMatcher::PATTERNS_KEY,
            \App\Core\Statistics\AccessStatisticsPolicy::ENABLED_KEY,
            \App\Core\Statistics\AccessStatisticsPolicy::RESPECT_DO_NOT_TRACK_KEY,
            MaxMindGeoIpConfig::ENABLED_KEY,
            MaxMindGeoIpConfig::DATABASE_PATH_KEY,
            MaxMindGeoIpConfig::LICENSE_KEY_KEY,
            ApiFeaturePolicy::ENABLED_KEY,
            ApiFeaturePolicy::CORS_ENABLED_KEY,
            ApiFeaturePolicy::CORS_ALLOWED_ORIGINS_KEY,
            SchedulerSettings::ENABLED_KEY,
            SchedulerSettings::GET_AUTH_ENABLED_KEY,
            SchedulerSettings::PACKAGE_ACTION_QUEUES_ENABLED_KEY,
            SchedulerSettings::WEB_TRIGGER_ENABLED_KEY,
        ];
        $inputOnlyKeys = [
            'site.title',
            'site.url',
            'localization.default_language',
        ];
        $seedKeys = array_keys((new SetupDefaultSeed())->configMap($this->input()));
        sort($seedKeys);
        $expectedKeys = [...$defaultKeys, ...$inputOnlyKeys];
        sort($expectedKeys);

        self::assertSame($expectedKeys, $seedKeys);
    }

    public function testItUsesValidLocalhostAdminEmailDefault(): void
    {
        self::assertSame('admin@localhost.local', SetupInput::withDefaults(defaultUri: 'http://localhost')->adminEmail());
    }

    public function testItDefinesSetupAclAndContentDefaults(): void
    {
        $seed = new SetupDefaultSeed();
        $input = $this->input(siteTitle: 'Seeded Studio', language: 'de');

        self::assertSame([], $seed->aclGroups());
        self::assertSame('/home', $seed->homePath());
        self::assertSame('static_page', $seed->contentSchema()['identifier']);
        self::assertSame(['title', 'subtitle', 'body', 'seo_title'], array_column($seed->contentSchemaVersion()['definition']['fields'], 'identifier'));
        self::assertSame('home', $seed->homeContentItem([$input->language()])['slug']);
        self::assertSame(['de'], $seed->homeContentItem([$input->language()])['available_languages']);
        self::assertSame('Seeded Studio', $seed->homeContentFields($input)['title']['de']);
    }

    private function input(
        string $siteTitle = 'Test Studio',
        string $defaultUri = 'https://example.test',
        string $language = 'en',
        array $siteSettings = [],
    ): SetupInput {
        return new SetupInput(
            appEnv: 'test',
            language: $language,
            siteTitle: $siteTitle,
            defaultUri: $defaultUri,
            databaseDriver: DatabaseDriver::SQLite,
            databaseUrl: 'sqlite:///%kernel.project_dir%/var/test/test.db',
            adminUsername: 'admin',
            adminPassword: 'Secret1!password',
            adminEmail: 'admin@example.test',
            siteSettings: $siteSettings,
        );
    }
}

final readonly class SetupSeedConfigDefaultProvider implements ConfigDefaultProviderInterface
{
    /**
     * @param array<string, mixed> $defaults
     */
    public function __construct(private array $defaults)
    {
    }

    public function hasDefault(string $key): bool
    {
        return array_key_exists($key, $this->defaults);
    }

    public function defaultValue(string $key): mixed
    {
        return $this->defaults[$key] ?? null;
    }
}
