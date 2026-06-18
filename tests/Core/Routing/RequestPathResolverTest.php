<?php

declare(strict_types=1);

namespace App\Tests\Core\Routing;

use App\Content\Routing\ContentRouteLocalization;
use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Core\Routing\RequestPathResolver;
use App\Localization\TranslationLanguageCatalog;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class RequestPathResolverTest extends TestCase
{
    public function testItStripsRouteLocaleOnlyBeforeKnownLocalePrefixPathScopes(): void
    {
        $resolver = new RequestPathResolver();
        $admin = Request::create('/de/admin/settings/security');
        $admin->attributes->set('_locale', 'de');
        $login = Request::create('/de/user/login');
        $login->attributes->set('_locale', 'de');
        $content = Request::create('/de/about');
        $content->attributes->set('_locale', 'de');

        self::assertSame(['admin', 'settings', 'security'], $resolver->segments($admin));
        self::assertSame(['user', 'login'], $resolver->segments($login));
        self::assertSame(['de', 'about'], $resolver->segments($content));
    }

    public function testItDoesNotStripLocalePrefixForPrefixlessTechnicalScopes(): void
    {
        $resolver = new RequestPathResolver();
        $api = Request::create('/de/api/v1/status');
        $api->attributes->set('_locale', 'de');
        $cron = Request::create('/de/cron/run');
        $cron->attributes->set('_locale', 'de');

        self::assertFalse($resolver->matches($api, 'api', 'v1'));
        self::assertFalse($resolver->matchesExact($cron, 'cron', 'run'));
        self::assertFalse($resolver->matches(Request::create('/de/api/v1/status'), 'api', 'v1'));
        self::assertFalse($resolver->matches(Request::create('/apiary'), 'api'));
    }

    public function testItUsesEnabledRoutePrefixLanguages(): void
    {
        $resolver = new RequestPathResolver($this->routeLocalization(true));

        self::assertTrue($resolver->matches(Request::create('/de/admin/logs'), 'admin'));
        self::assertFalse($resolver->matches(Request::create('/de/api/v1/status'), 'api', 'v1'));
        self::assertFalse($resolver->matchesExact(Request::create('/de/cron/run'), 'cron', 'run'));
        self::assertFalse($resolver->matches(Request::create('/fr/api/v1/status'), 'api', 'v1'));
        self::assertFalse((new RequestPathResolver($this->routeLocalization(false)))->matches(Request::create('/de/api/v1/status'), 'api', 'v1'));
    }

    private function routeLocalization(bool $enabled): ContentRouteLocalization
    {
        $config = new Config($this->connection());
        $config->set(ContentRouteLocalization::ENABLED_KEY, $enabled, ConfigValueType::Boolean);

        return new ContentRouteLocalization($config, new TranslationLanguageCatalog(dirname(__DIR__, 3)));
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) NOT NULL PRIMARY KEY, value CLOB NOT NULL, value_type VARCHAR(32) NOT NULL, sensitive BOOLEAN NOT NULL DEFAULT 0, modified_at DATETIME DEFAULT NULL, modified_by VARCHAR(180) DEFAULT NULL)');

        return $connection;
    }
}
