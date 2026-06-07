<?php

declare(strict_types=1);

namespace App\Tests\Localization;

use App\Content\Routing\ContentRouteLocalization;
use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Entity\UserAccount;
use App\Localization\LocalePreferenceResolver;
use App\Localization\RequestLocaleSubscriber;
use App\Localization\TranslationLanguageCatalog;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Translation\LocaleSwitcher;

final class RequestLocaleSubscriberTest extends TestCase
{
    public function testItAppliesConfiguredDefaultLanguage(): void
    {
        $request = Request::create('/admin', server: ['HTTP_ACCEPT_LANGUAGE' => '']);
        $subscriber = $this->subscriber($this->localization('de'), new TokenStorage());

        $subscriber->onKernelRequest($this->event($request));

        self::assertSame('de', $request->getLocale());
    }

    public function testItPrefersUserLanguageOverConfiguredDefault(): void
    {
        $request = Request::create('/admin', server: ['HTTP_ACCEPT_LANGUAGE' => '']);
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(new UserAccount(
            '77777777-7777-7777-8777-777777777777',
            'localeuser',
            'locale@example.test',
            'hash',
            settings: ['language' => 'de'],
        ), 'main'));

        $subscriber = $this->subscriber($this->localization('en'), $tokenStorage);

        $subscriber->onKernelRequest($this->event($request));

        self::assertSame('de', $request->getLocale());
    }

    public function testItPrefersSupportedUrlLocaleOverUserLanguageWhenRoutePrefixesAreEnabled(): void
    {
        $request = Request::create('/de/articles');
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(new UserAccount(
            '77777777-7777-7777-8777-777777777779',
            'urlprefuser',
            'url-pref@example.test',
            'hash',
            settings: ['language' => 'en'],
        ), 'main'));

        $subscriber = $this->subscriber($this->localization('en', routePrefixesEnabled: true), $tokenStorage);

        $subscriber->onKernelRequest($this->event($request));

        self::assertSame('de', $request->getLocale());
    }

    public function testItIgnoresUnsupportedUserLanguageAndUsesSessionLanguage(): void
    {
        $request = Request::create('/admin', server: ['HTTP_ACCEPT_LANGUAGE' => '']);
        $session = new Session(new MockArraySessionStorage());
        $session->set('_locale', 'de');
        $request->setSession($session);
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(new UserAccount(
            '77777777-7777-7777-8777-777777777778',
            'staleuserlocale',
            'stale-locale@example.test',
            'hash',
            settings: ['language' => 'fr'],
        ), 'main'));

        $subscriber = $this->subscriber($this->localization('en'), $tokenStorage);

        $subscriber->onKernelRequest($this->event($request));

        self::assertSame('de', $request->getLocale());
    }

    public function testItKeepsSessionLanguageBeforeUserLanguage(): void
    {
        $request = Request::create('/admin', server: ['HTTP_ACCEPT_LANGUAGE' => '']);
        $session = new Session(new MockArraySessionStorage());
        $session->set('_locale', 'de');
        $request->setSession($session);
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(new UserAccount(
            '77777777-7777-7777-8777-777777777782',
            'sessionprefuser',
            'session-pref@example.test',
            'hash',
            settings: ['language' => 'en'],
        ), 'main'));

        $subscriber = $this->subscriber($this->localization('en'), $tokenStorage);

        $subscriber->onKernelRequest($this->event($request));

        self::assertSame('de', $request->getLocale());
    }

    public function testItUsesSessionLanguageBeforeConfiguredDefault(): void
    {
        $request = Request::create('/admin');
        $session = new Session(new MockArraySessionStorage());
        $session->set('_locale', 'de');
        $request->setSession($session);
        $subscriber = $this->subscriber($this->localization('en'), new TokenStorage());

        $subscriber->onKernelRequest($this->event($request));

        self::assertSame('de', $request->getLocale());
    }

    public function testItKeepsSessionLanguageBeforeAcceptLanguage(): void
    {
        $request = Request::create('/admin', server: ['HTTP_ACCEPT_LANGUAGE' => 'de-DE, en;q=0.7']);
        $session = new Session(new MockArraySessionStorage());
        $session->set('_locale', 'en');
        $request->setSession($session);
        $subscriber = $this->subscriber($this->localization('en'), new TokenStorage());

        $subscriber->onKernelRequest($this->event($request));

        self::assertSame('en', $request->getLocale());
    }

    public function testItUsesAcceptLanguageBeforeConfiguredDefault(): void
    {
        $request = Request::create('/admin', server: ['HTTP_ACCEPT_LANGUAGE' => 'de-DE, en;q=0.7']);
        $subscriber = $this->subscriber($this->localization('en'), new TokenStorage());

        $subscriber->onKernelRequest($this->event($request));

        self::assertSame('de', $request->getLocale());
    }

    public function testItKeepsUserLanguageBeforeAcceptLanguage(): void
    {
        $request = Request::create('/admin', server: ['HTTP_ACCEPT_LANGUAGE' => 'de-DE, en;q=0.7']);
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(new UserAccount(
            '77777777-7777-7777-8777-777777777781',
            'headerprefuser',
            'header-pref@example.test',
            'hash',
            settings: ['language' => 'en'],
        ), 'main'));

        $subscriber = $this->subscriber($this->localization('de'), $tokenStorage);

        $subscriber->onKernelRequest($this->event($request));

        self::assertSame('en', $request->getLocale());
    }

    public function testItPrefersLanguageQueryOverUserLanguage(): void
    {
        $request = Request::create('/api/v1/content/items', 'GET', ['language' => 'de']);
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(new UserAccount(
            '77777777-7777-7777-8777-777777777780',
            'apiuserlocale',
            'api-locale@example.test',
            'hash',
            settings: ['language' => 'en'],
        ), 'api'));

        $subscriber = $this->subscriber($this->localization('en'), $tokenStorage);

        $subscriber->onKernelRequest($this->event($request));

        self::assertSame('de', $request->getLocale());
    }

    public function testItPrefersLanguageQueryOverSupportedUrlLocale(): void
    {
        $request = Request::create('/en/articles', 'GET', ['language' => 'de']);
        $subscriber = $this->subscriber($this->localization('en', routePrefixesEnabled: true), new TokenStorage());

        $subscriber->onKernelRequest($this->event($request));

        self::assertSame('de', $request->getLocale());
    }

    private function localization(string $defaultLanguage, bool $routePrefixesEnabled = false): ContentRouteLocalization
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) NOT NULL PRIMARY KEY, value CLOB NOT NULL, value_type VARCHAR(32) NOT NULL, sensitive BOOLEAN NOT NULL, modified_at DATETIME NOT NULL, modified_by VARCHAR(180) DEFAULT NULL)');
        $config = new Config($connection);
        $config->set(ContentRouteLocalization::DEFAULT_LANGUAGE_KEY, $defaultLanguage, ConfigValueType::String);
        $config->set(ContentRouteLocalization::ENABLED_KEY, $routePrefixesEnabled, ConfigValueType::Boolean);

        return new ContentRouteLocalization($config, new TranslationLanguageCatalog(dirname(__DIR__, 2)));
    }

    private function subscriber(ContentRouteLocalization $localization, TokenStorage $tokenStorage): RequestLocaleSubscriber
    {
        return new RequestLocaleSubscriber(
            $localization,
            $tokenStorage,
            $this->localeSwitcher(),
            new LocalePreferenceResolver($localization),
        );
    }

    private function event(Request $request): RequestEvent
    {
        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }

    private function localeSwitcher(): LocaleSwitcher
    {
        return new LocaleSwitcher('en', []);
    }
}
