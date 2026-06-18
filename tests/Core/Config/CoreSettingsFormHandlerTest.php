<?php

declare(strict_types=1);

namespace App\Tests\Core\Config;

use App\Core\Access\AccessActor;
use App\Core\Access\AccessLevel;
use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Core\Config\Settings\CoreSettingsFormHandler;
use App\Core\Config\Settings\CoreSettingsRegistry;
use App\Core\Geo\MaxMindGeoIpConfig;
use App\Core\Log\ConfigAuditLogPolicy;
use App\Core\Log\DatabaseLogRetentionPolicy;
use App\Form\FormSubmissionHandler;
use App\Form\FormErrorKey;
use App\Localization\TranslationLanguageCatalog;
use App\Security\Abuse\SuspiciousProbePathMatcher;
use App\Security\AutoBan\AutoBanPolicy;
use App\Security\RateLimit\RateLimitPolicyCatalogue;
use App\Security\RateLimit\RateLimitProfile;
use App\View\SystemPackageMetadataProvider;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class CoreSettingsFormHandlerTest extends TestCase
{
    public function testItPreservesExistingSensitiveSettingsWhenSubmittedEmpty(): void
    {
        $config = new Config($this->connection());
        $config->set(MaxMindGeoIpConfig::LICENSE_KEY_KEY, 'secret-license-key', ConfigValueType::String, sensitive: true);

        $handler = new CoreSettingsFormHandler(
            $this->registry(),
            $config,
            new FormSubmissionHandler(),
            $this->createStub(EntityManagerInterface::class),
        );

        $result = $handler->submit('statistics', [
            'statistics.enabled' => '1',
            'statistics.respect_do_not_track' => '1',
            MaxMindGeoIpConfig::ENABLED_KEY => '0',
            MaxMindGeoIpConfig::DATABASE_PATH_KEY => MaxMindGeoIpConfig::DEFAULT_DATABASE_PATH,
            MaxMindGeoIpConfig::LICENSE_KEY_KEY => '',
        ], 'test', AccessActor::fromAccess(AccessLevel::OWNER));

        self::assertTrue($result->isValid());
        self::assertSame('secret-license-key', $config->get(MaxMindGeoIpConfig::LICENSE_KEY_KEY));
    }

    public function testItPreservesExistingSensitiveSettingsWhenSubmittedProtectedPlaceholder(): void
    {
        $config = new Config($this->connection());
        $config->set(MaxMindGeoIpConfig::LICENSE_KEY_KEY, 'secret-license-key', ConfigValueType::String, sensitive: true);

        $handler = new CoreSettingsFormHandler(
            $this->registry(),
            $config,
            new FormSubmissionHandler(),
            $this->createStub(EntityManagerInterface::class),
        );

        $result = $handler->submit('statistics', [
            'statistics.enabled' => '1',
            'statistics.respect_do_not_track' => '1',
            MaxMindGeoIpConfig::ENABLED_KEY => '1',
            MaxMindGeoIpConfig::DATABASE_PATH_KEY => MaxMindGeoIpConfig::DEFAULT_DATABASE_PATH,
            MaxMindGeoIpConfig::LICENSE_KEY_KEY => '[protected]',
        ], 'test', AccessActor::fromAccess(AccessLevel::OWNER));

        self::assertTrue($result->isValid());
        self::assertSame('secret-license-key', $config->get(MaxMindGeoIpConfig::LICENSE_KEY_KEY));
    }

    public function testItInvalidatesSuspiciousProbePatternCacheWhenSecuritySettingsChange(): void
    {
        $config = new Config($this->connection());
        $config->set(SuspiciousProbePathMatcher::PATTERNS_KEY, '#/old-probe$#', ConfigValueType::String);
        $cache = new ArrayAdapter();
        $matcher = new SuspiciousProbePathMatcher($config, cache: $cache);

        self::assertTrue($matcher->isProbe('/old-probe'));

        $handler = new CoreSettingsFormHandler(
            $this->registry(),
            $config,
            new FormSubmissionHandler(),
            $this->createStub(EntityManagerInterface::class),
            $matcher,
        );

        $result = $handler->submit('security', [
            'security.captcha.enabled' => '0',
            'security.captcha.provider' => 'none',
            RateLimitPolicyCatalogue::MODE_KEY => RateLimitProfile::Strict->value,
            AutoBanPolicy::ENABLED_KEY => '1',
            AutoBanPolicy::TRUSTED_ACCESS_LEVEL_KEY => (string) AutoBanPolicy::DEFAULT_TRUSTED_ACCESS_LEVEL,
            AutoBanPolicy::SCORE_THRESHOLD_KEY => (string) AutoBanPolicy::DEFAULT_SCORE_THRESHOLD,
            AutoBanPolicy::NEW_BAN_OWNER_ALERTS_KEY => '1',
            ConfigAuditLogPolicy::ENABLED_KEY => '1',
            ConfigAuditLogPolicy::EVENTS_KEY => ConfigAuditLogPolicy::DEFAULT_CATEGORIES,
            DatabaseLogRetentionPolicy::SECURITY_SIGNAL_RETENTION_DAYS_KEY => '7',
            SuspiciousProbePathMatcher::PATTERNS_KEY => '#/new-probe$#',
        ], 'test');

        self::assertTrue($result->isValid());
        self::assertSame(RateLimitProfile::Strict->value, $config->get(RateLimitPolicyCatalogue::MODE_KEY));
        self::assertTrue((new SuspiciousProbePathMatcher($config, cache: $cache))->isProbe('/new-probe'));
        self::assertFalse((new SuspiciousProbePathMatcher($config, cache: $cache))->isProbe('/old-probe'));
    }

    public function testItRejectsInvalidRateLimitModes(): void
    {
        $config = new Config($this->connection());
        $handler = new CoreSettingsFormHandler(
            $this->registry(),
            $config,
            new FormSubmissionHandler(),
            $this->createStub(EntityManagerInterface::class),
        );

        $result = $handler->submit('security', [
            'security.captcha.enabled' => '0',
            'security.captcha.provider' => 'none',
            RateLimitPolicyCatalogue::MODE_KEY => 'forever',
            AutoBanPolicy::ENABLED_KEY => '1',
            AutoBanPolicy::TRUSTED_ACCESS_LEVEL_KEY => (string) AutoBanPolicy::DEFAULT_TRUSTED_ACCESS_LEVEL,
            AutoBanPolicy::SCORE_THRESHOLD_KEY => (string) AutoBanPolicy::DEFAULT_SCORE_THRESHOLD,
            AutoBanPolicy::NEW_BAN_OWNER_ALERTS_KEY => '1',
            ConfigAuditLogPolicy::ENABLED_KEY => '1',
            ConfigAuditLogPolicy::EVENTS_KEY => ConfigAuditLogPolicy::DEFAULT_CATEGORIES,
            DatabaseLogRetentionPolicy::SECURITY_SIGNAL_RETENTION_DAYS_KEY => '7',
            SuspiciousProbePathMatcher::PATTERNS_KEY => SuspiciousProbePathMatcher::defaultPatternText(),
        ], 'test');

        self::assertFalse($result->isValid());
        self::assertSame(['admin.settings.form.errors.choice'], $result->errors()[RateLimitPolicyCatalogue::MODE_KEY]);
        self::assertNull($config->get(RateLimitPolicyCatalogue::MODE_KEY));
    }

    public function testItRejectsSecuritySignalRetentionBelowMaximumAutoBanTtl(): void
    {
        $config = new Config($this->connection());
        $handler = new CoreSettingsFormHandler(
            $this->registry(),
            $config,
            new FormSubmissionHandler(),
            $this->createStub(EntityManagerInterface::class),
        );

        $result = $handler->submit('security', [
            'security.captcha.enabled' => '0',
            'security.captcha.provider' => 'none',
            RateLimitPolicyCatalogue::MODE_KEY => RateLimitProfile::Strict->value,
            AutoBanPolicy::ENABLED_KEY => '1',
            AutoBanPolicy::TRUSTED_ACCESS_LEVEL_KEY => (string) AutoBanPolicy::DEFAULT_TRUSTED_ACCESS_LEVEL,
            AutoBanPolicy::SCORE_THRESHOLD_KEY => (string) AutoBanPolicy::DEFAULT_SCORE_THRESHOLD,
            AutoBanPolicy::NEW_BAN_OWNER_ALERTS_KEY => '1',
            ConfigAuditLogPolicy::ENABLED_KEY => '1',
            ConfigAuditLogPolicy::EVENTS_KEY => ConfigAuditLogPolicy::DEFAULT_CATEGORIES,
            DatabaseLogRetentionPolicy::SECURITY_SIGNAL_RETENTION_DAYS_KEY => (string) (AutoBanPolicy::maxTtlDays() - 1),
            SuspiciousProbePathMatcher::PATTERNS_KEY => SuspiciousProbePathMatcher::defaultPatternText(),
        ], 'test');

        self::assertFalse($result->isValid());
        self::assertSame([FormErrorKey::MIN], $result->errors()[DatabaseLogRetentionPolicy::SECURITY_SIGNAL_RETENTION_DAYS_KEY]);
        self::assertNull($config->get(DatabaseLogRetentionPolicy::SECURITY_SIGNAL_RETENTION_DAYS_KEY));
    }

    private function registry(): CoreSettingsRegistry
    {
        $projectDir = dirname(__DIR__, 3);

        return new CoreSettingsRegistry(new TranslationLanguageCatalog($projectDir), new SystemPackageMetadataProvider($projectDir));
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) NOT NULL PRIMARY KEY, value CLOB NOT NULL, value_type VARCHAR(32) NOT NULL, sensitive BOOLEAN NOT NULL DEFAULT 0, modified_at DATETIME DEFAULT NULL, modified_by VARCHAR(180) DEFAULT NULL)');

        return $connection;
    }
}
