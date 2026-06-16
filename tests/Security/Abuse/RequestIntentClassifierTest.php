<?php

declare(strict_types=1);

namespace App\Tests\Security\Abuse;

use App\Security\Abuse\RequestFamily;
use App\Security\Abuse\RequestIntent;
use App\Security\Abuse\RequestIntentClassifier;
use App\Localization\TranslationLanguageCatalog;
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
            Request::create('/de/api/live/alerts'),
            RequestFamily::LiveApi,
            RequestIntent::LiveApi,
        ];
        yield 'api write' => [
            Request::create('/api/v1/content/items', 'POST'),
            RequestFamily::Api,
            RequestIntent::ApiWrite,
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
            Request::create('/de/admin/settings/security', 'POST'),
            RequestFamily::Admin,
            RequestIntent::SettingsMutation,
        ];
        yield 'public path containing reserved segment is public' => [
            Request::create('/docs/api/reference', 'POST'),
            RequestFamily::Browser,
            RequestIntent::FormSubmit,
        ];
        yield 'cors preflight' => [
            Request::create('/api/v1/content/items', 'OPTIONS'),
            RequestFamily::Api,
            RequestIntent::CorsPreflight,
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
        $profile = (new RequestIntentClassifier(languageCatalog: $this->languageCatalog()))->classify($request);

        self::assertSame($family, $profile->family());
        self::assertSame($intent, $profile->intent());
    }

    public function testItDoesNotTreatOrdinaryUploadRoutesAsProbePaths(): void
    {
        $profile = (new RequestIntentClassifier())->classify(Request::create('/admin/packages/upload', 'POST'));

        self::assertSame(RequestIntent::PackageAdminOperation, $profile->intent());
        self::assertFalse($profile->suspiciousProbe());
    }

    private function languageCatalog(): TranslationLanguageCatalog
    {
        return new TranslationLanguageCatalog(dirname(__DIR__, 3));
    }
}
