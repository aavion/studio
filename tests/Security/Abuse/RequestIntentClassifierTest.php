<?php

declare(strict_types=1);

namespace App\Tests\Security\Abuse;

use App\Security\Abuse\RequestFamily;
use App\Security\Abuse\RequestIntent;
use App\Security\Abuse\RequestIntentClassifier;
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
        yield 'api write' => [
            Request::create('/api/v1/content/items', 'POST'),
            RequestFamily::Api,
            RequestIntent::ApiWrite,
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
        yield 'suspicious env probe' => [
            Request::create('/.env'),
            RequestFamily::Browser,
            RequestIntent::SuspiciousProbe,
        ];
    }

    #[DataProvider('requestCases')]
    public function testItClassifiesRequestIntent(Request $request, RequestFamily $family, RequestIntent $intent): void
    {
        $profile = (new RequestIntentClassifier())->classify($request);

        self::assertSame($family, $profile->family());
        self::assertSame($intent, $profile->intent());
    }

    public function testItDoesNotTreatOrdinaryUploadRoutesAsProbePaths(): void
    {
        $profile = (new RequestIntentClassifier())->classify(Request::create('/admin/packages/upload', 'POST'));

        self::assertSame(RequestIntent::PackageAdminOperation, $profile->intent());
        self::assertFalse($profile->suspiciousProbe());
    }
}
