<?php

declare(strict_types=1);

namespace App\Tests\Security\Abuse;

use App\Content\Routing\ContentRouteLocalization;
use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Localization\TranslationLanguageCatalog;
use App\Security\Abuse\RequestFamily;
use App\Security\Abuse\RequestIntent;
use App\Security\Abuse\RequestIntentClassifier;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class RequestIntentClassifierTest extends TestCase
{
    /**
     * @return iterable<string, array{Request, RequestFamily, RequestIntent}>
     */
    public static function requestCases(): iterable
    {
        yield 'live api cheap json' => [
            Request::create('/api/live/alerts'),
            RequestFamily::LiveApi,
            RequestIntent::LiveApi,
        ];
        yield 'localized live api cheap json' => [
            self::localizedRequest('/de/api/live/alerts', 'GET', 'de'),
            RequestFamily::LiveApi,
            RequestIntent::LiveApi,
        ];
        yield 'api write' => [
            Request::create('/api/v1/content/items', 'POST'),
            RequestFamily::Api,
            RequestIntent::ApiWrite,
        ];
        yield 'admin api operation mutation is admin mutation' => [
            Request::create('/api/v1/admin/operations/cleanup', 'POST'),
            RequestFamily::Api,
            RequestIntent::AdminOperation,
        ];
        yield 'admin api settings mutation is settings mutation' => [
            Request::create('/api/v1/admin/settings/security', 'PATCH'),
            RequestFamily::Api,
            RequestIntent::SettingsMutation,
        ];
        yield 'admin api scheduler mutation is admin mutation' => [
            Request::create('/api/v1/admin/scheduler/system.live_operation_cleanup', 'PATCH'),
            RequestFamily::Api,
            RequestIntent::AdminOperation,
        ];
        yield 'localized admin api package mutation is package admin mutation' => [
            self::localizedRequest('/de/api/v1/admin/packages/demo/reset-fault', 'POST', 'de'),
            RequestFamily::Api,
            RequestIntent::PackageAdminOperation,
        ];
        yield 'apiary public content is not api' => [
            Request::create('/apiary'),
            RequestFamily::Browser,
            RequestIntent::BrowserNavigation,
        ];
        yield 'administer public content is not admin' => [
            Request::create('/administer', 'POST'),
            RequestFamily::Browser,
            RequestIntent::FormSubmit,
        ];
        yield 'localized admin is admin' => [
            self::localizedRequest('/de/admin/settings/security', 'POST', 'de'),
            RequestFamily::Admin,
            RequestIntent::SettingsMutation,
        ];
        yield 'admin package upload uses upload archive bucket before broad package bucket' => [
            Request::create('/admin/packages/upload', 'POST'),
            RequestFamily::Admin,
            RequestIntent::UploadArchiveValidation,
        ];
        yield 'admin download uses download diagnostics bucket even for safe method' => [
            Request::create('/admin/logs/download'),
            RequestFamily::Admin,
            RequestIntent::ExportDownload,
        ];
        yield 'public path containing reserved segment is public' => [
            Request::create('/docs/api/reference', 'POST'),
            RequestFamily::Browser,
            RequestIntent::FormSubmit,
        ];
        yield 'future content download path is ordinary navigation' => [
            self::contentRequest('/download'),
            RequestFamily::Browser,
            RequestIntent::BrowserNavigation,
        ];
        yield 'future localized content export path is ordinary form submit' => [
            self::contentRequest('/de/export', 'POST', 'de'),
            RequestFamily::Browser,
            RequestIntent::FormSubmit,
        ];
        yield 'future public package form post gets website form intent' => [
            self::contentRequest('/forum/thread/welcome', 'POST'),
            RequestFamily::Browser,
            RequestIntent::FormSubmit,
        ];
        yield 'contact-like content slug is ordinary navigation' => [
            self::contentRequest('/contact-us'),
            RequestFamily::Browser,
            RequestIntent::BrowserNavigation,
        ];
        yield 'contact content slug is ordinary navigation' => [
            self::contentRequest('/contact'),
            RequestFamily::Browser,
            RequestIntent::BrowserNavigation,
        ];
        yield 'contact content post is ordinary form submit' => [
            self::contentRequest('/contact', 'POST'),
            RequestFamily::Browser,
            RequestIntent::FormSubmit,
        ];
        yield 'captcha refresh content slug is ordinary navigation' => [
            self::contentRequest('/captcha/refresh'),
            RequestFamily::Browser,
            RequestIntent::BrowserNavigation,
        ];
        yield 'localized captcha refresh content slug is ordinary navigation' => [
            self::contentRequest('/de/captcha/refresh', 'GET', 'de'),
            RequestFamily::Browser,
            RequestIntent::BrowserNavigation,
        ];
        yield 'admin path containing settings only as part of a segment is generic admin' => [
            Request::create('/admin/content/site-settings', 'POST'),
            RequestFamily::Admin,
            RequestIntent::AdminOperation,
        ];
        yield 'cors preflight' => [
            Request::create('/api/v1/content/items', 'OPTIONS'),
            RequestFamily::Api,
            RequestIntent::CorsPreflight,
        ];
        yield 'bearer options request is charged as api read' => [
            Request::create('/api/v1/content/items', 'OPTIONS', server: [
                'HTTP_AUTHORIZATION' => 'Bearer invalid.token',
            ]),
            RequestFamily::Api,
            RequestIntent::ApiRead,
        ];
        yield 'bearer options request honors requested unsafe admin method' => [
            Request::create('/api/v1/admin/settings/security', 'OPTIONS', server: [
                'HTTP_AUTHORIZATION' => 'Bearer invalid.token',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PATCH',
            ]),
            RequestFamily::Api,
            RequestIntent::SettingsMutation,
        ];
        yield 'turbo prefetch' => [
            Request::create('/docs', server: ['HTTP_SEC_PURPOSE' => 'prefetch']),
            RequestFamily::Browser,
            RequestIntent::TurboPrefetch,
        ];
        yield 'scheduler trigger' => [
            Request::create('/cron/run'),
            RequestFamily::Scheduler,
            RequestIntent::SchedulerTrigger,
        ];
        yield 'setup apply' => [
            Request::create('/setup', 'POST'),
            RequestFamily::Setup,
            RequestIntent::SetupApply,
        ];
        yield 'settings mutation' => [
            Request::create('/admin/settings/security', 'POST'),
            RequestFamily::Admin,
            RequestIntent::SettingsMutation,
        ];
        yield 'admin user password reset is acl mutation' => [
            Request::create('/admin/users/details/example-user/password-reset', 'POST'),
            RequestFamily::Admin,
            RequestIntent::UserAclMutation,
        ];
        yield 'admin package reset fault is package mutation' => [
            Request::create('/admin/packages/demo/reset-fault', 'POST'),
            RequestFamily::Admin,
            RequestIntent::PackageAdminOperation,
        ];
        yield 'admin operation post is generic admin mutation' => [
            Request::create('/admin/operations', 'POST'),
            RequestFamily::Admin,
            RequestIntent::AdminOperation,
        ];
        yield 'login form render is ordinary navigation' => [
            Request::create('/user/login'),
            RequestFamily::Browser,
            RequestIntent::BrowserNavigation,
        ];
        yield 'recovery login bypass uses recovery intent' => [
            Request::create('/user/login?bypass=1'),
            RequestFamily::Browser,
            RequestIntent::RecoveryLogin,
        ];
        yield 'recovery login bypass post stays login intent' => [
            Request::create('/user/login?bypass=1', 'POST'),
            RequestFamily::Browser,
            RequestIntent::Login,
        ];
        yield 'registration form render is ordinary navigation' => [
            Request::create('/user/register'),
            RequestFamily::Browser,
            RequestIntent::BrowserNavigation,
        ];
        yield 'password reset form render is ordinary navigation' => [
            Request::create('/user/reset-password'),
            RequestFamily::Browser,
            RequestIntent::BrowserNavigation,
        ];
        yield 'public login post is login intent' => [
            Request::create('/user/login', 'POST'),
            RequestFamily::Browser,
            RequestIntent::Login,
        ];
        yield 'public password reset stays public reset intent' => [
            Request::create('/user/password-reset', 'POST'),
            RequestFamily::Browser,
            RequestIntent::PasswordReset,
        ];
        $tokenReset = Request::create('/user/reset-password/'.str_repeat('a', 64), 'POST');
        $tokenReset->attributes->set('_route', 'user_password_reset_token');
        yield 'public reset token route is password reset' => [
            $tokenReset,
            RequestFamily::Browser,
            RequestIntent::PasswordReset,
        ];
        $invitation = Request::create('/user/invitation/'.str_repeat('b', 64), 'POST');
        $invitation->attributes->set('_route', 'user_invitation_accept');
        yield 'public invitation token route is registration' => [
            $invitation,
            RequestFamily::Browser,
            RequestIntent::Registration,
        ];
        yield 'suspicious env probe' => [
            Request::create('/.env'),
            RequestFamily::Browser,
            RequestIntent::SuspiciousProbe,
        ];
    }

    #[DataProvider('requestCases')]
    public function testItClassifiesRequestIntent(Request $request, RequestFamily $family, RequestIntent $intent): void
    {
        $profile = (new RequestIntentClassifier(routeLocalization: $this->routeLocalization()))->classify($request);

        self::assertSame($family, $profile->family());
        self::assertSame($intent, $profile->intent());
    }

    public function testItClassifiesOrdinaryUploadRoutesAsUploadArchiveValidation(): void
    {
        $profile = (new RequestIntentClassifier())->classify(Request::create('/admin/packages/upload', 'POST'));

        self::assertSame(RequestIntent::UploadArchiveValidation, $profile->intent());
        self::assertFalse($profile->suspiciousProbe());
    }

    public function testItDoesNotStripLanguageSlugsWhenRoutePrefixesAreDisabled(): void
    {
        $classifier = new RequestIntentClassifier(routeLocalization: $this->disabledRouteLocalization());
        $profile = $classifier->classify(self::contentRequest('/de/admin', 'POST'));
        $apiProfile = $classifier->classify(self::contentRequest('/de/api/v1/content/items', 'POST'));

        self::assertSame(RequestFamily::Browser, $profile->family());
        self::assertSame(RequestIntent::FormSubmit, $profile->intent());
        self::assertSame(RequestFamily::Browser, $apiProfile->family());
        self::assertSame(RequestIntent::FormSubmit, $apiProfile->intent());
    }

    private function routeLocalization(): ContentRouteLocalization
    {
        $config = new Config($this->connection());
        $config->set(ContentRouteLocalization::ENABLED_KEY, true, ConfigValueType::Boolean);

        return new ContentRouteLocalization($config, $this->languageCatalog());
    }

    private function disabledRouteLocalization(): ContentRouteLocalization
    {
        return new ContentRouteLocalization(new Config($this->connection()), $this->languageCatalog());
    }

    private function languageCatalog(): TranslationLanguageCatalog
    {
        return new TranslationLanguageCatalog(dirname(__DIR__, 3));
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) NOT NULL PRIMARY KEY, value CLOB NOT NULL, value_type VARCHAR(32) NOT NULL, sensitive BOOLEAN NOT NULL DEFAULT 0, modified_at DATETIME DEFAULT NULL, modified_by VARCHAR(180) DEFAULT NULL)');

        return $connection;
    }

    private static function contentRequest(string $path, string $method = 'GET', ?string $locale = null): Request
    {
        $request = Request::create($path, $method);
        $request->attributes->set('_route', 'content_show');
        if (null !== $locale) {
            $request->attributes->set('_locale', $locale);
        }

        return $request;
    }

    private static function localizedRequest(string $path, string $method, string $locale): Request
    {
        $request = Request::create($path, $method);
        $request->attributes->set('_locale', $locale);

        return $request;
    }
}
