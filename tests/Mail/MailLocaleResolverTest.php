<?php

declare(strict_types=1);

namespace App\Tests\Mail;

use App\Content\Routing\ContentRouteLocalization;
use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Entity\UserAccount;
use App\Localization\TranslationLanguageCatalog;
use App\Mail\MailLocaleResolver;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class MailLocaleResolverTest extends TestCase
{
    public function testItPrefersUserLanguageForPublicAndAdminFlows(): void
    {
        $resolver = $this->resolver('de');
        $user = new UserAccount('55555555-5555-7555-8555-555555555555', 'localeuser', 'locale@example.test', 'hash', settings: [
            'language' => 'de',
        ]);
        $request = Request::create('/user/reset-password');
        $request->setLocale('en');

        self::assertSame('de', $resolver->forPublicRequest($request, $user));
        self::assertSame('de', $resolver->forAdminAction($user));
    }

    public function testItFallsBackToRequestLocaleForPublicFlows(): void
    {
        $resolver = $this->resolver('de');
        $request = Request::create('/user/register');
        $request->setLocale('en');

        self::assertSame('en', $resolver->forPublicRequest($request));
    }

    public function testItIgnoresUnsupportedUserLanguageBeforePublicRequestLocaleFallback(): void
    {
        $resolver = $this->resolver('de');
        $user = new UserAccount('55555555-5555-7555-8555-555555555556', 'staleuserlocale', 'stale-locale@example.test', 'hash', settings: [
            'language' => 'fr',
        ]);
        $request = Request::create('/user/reset-password');
        $request->setLocale('en');

        self::assertSame('en', $resolver->forPublicRequest($request, $user));
    }


    public function testItFallsBackToDefaultLocaleForAdminFlows(): void
    {
        $resolver = $this->resolver('de');

        self::assertSame('de', $resolver->forAdminAction());
        self::assertSame('de', $resolver->defaultLocale());
    }

    private function resolver(string $defaultLanguage): MailLocaleResolver
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) NOT NULL PRIMARY KEY, value CLOB NOT NULL, value_type VARCHAR(32) NOT NULL, sensitive BOOLEAN NOT NULL DEFAULT 0, modified_at DATETIME DEFAULT NULL, modified_by VARCHAR(180) DEFAULT NULL)');
        $config = new Config($connection);
        $config->set(ContentRouteLocalization::DEFAULT_LANGUAGE_KEY, $defaultLanguage, ConfigValueType::String);

        return new MailLocaleResolver(new ContentRouteLocalization(
            $config,
            new TranslationLanguageCatalog(dirname(__DIR__, 2)),
        ));
    }
}
