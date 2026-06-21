<?php

declare(strict_types=1);

namespace App\Tests\Security\AutoBan;

use App\Content\Read\PublishedContentResolver;
use App\Content\Render\ContentFieldsetRenderer;
use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Core\Log\AccessRequestMetadata;
use App\Core\Statistics\VisitorIdGenerator;
use App\Entity\UserAccount;
use App\Security\Abuse\AbuseRequestInspector;
use App\Security\Abuse\AbuseSubjectResolver;
use App\Security\Abuse\ActionCostCatalogue;
use App\Security\Abuse\PassiveAbuseSignalSubscriber;
use App\Security\Abuse\RequestIntentClassifier;
use App\Security\Abuse\SuspiciousPayloadSignalSubscriber;
use App\Security\AutoBan\AutoBanPolicy;
use App\Security\AutoBan\AutoBanRequestSubscriber;
use App\Security\AutoBan\AutoBanStore;
use App\Security\AutoBan\AutoBanSubject;
use App\Security\HttpErrorSecurityHandler;
use App\Security\RateLimit\RateLimitRequestSubscriber;
use App\Security\UserRole;
use App\Setup\SetupCompletionMarker;
use App\View\Http\HttpErrorRenderer;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class AutoBanRequestSubscriberTest extends TestCase
{
    public function testActiveVisitorBanReturnsBareForbiddenBeforeApplicationHandling(): void
    {
        $clock = new MockClock('2026-06-18 12:00:00');
        $visitorIds = new VisitorIdGenerator('test-secret');
        $store = new AutoBanStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()), clock: $clock);
        $request = Request::create('/missing', server: ['REMOTE_ADDR' => '203.0.113.10']);
        $request->attributes->set(AccessRequestMetadata::REQUEST_ID_ATTRIBUTE, 'request-ban');
        $subject = new AutoBanSubject(AutoBanSubject::VISITOR, $visitorIds->generate($request));
        $store->ban($subject, 3600);
        $event = new RequestEvent(new AutoBanRequestTestKernel(), $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber($visitorIds, $store, $clock)->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('3600', $response->headers->get('Retry-After'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('Request blocked due to suspicious activity.', (string) $response->getContent());
        self::assertStringContainsString('request-ban', (string) $response->getContent());
        self::assertTrue($request->attributes->getBoolean(AutoBanRequestSubscriber::PASSIVE_SIGNAL_SKIP_ATTRIBUTE));
        self::assertTrue($request->attributes->getBoolean(AccessRequestMetadata::FORCE_ACCESS_LOG_ATTRIBUTE));
    }

    public function testActiveVisitorBanOverridesEarlierProbeResponseAndSkipsPassiveSignals(): void
    {
        $clock = new MockClock('2026-06-18 12:00:00');
        $visitorIds = new VisitorIdGenerator('test-secret');
        $store = new AutoBanStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()), clock: $clock);
        $request = Request::create('/.env', server: ['REMOTE_ADDR' => '203.0.113.10']);
        $subject = new AutoBanSubject(AutoBanSubject::VISITOR, $visitorIds->generate($request));
        $store->ban($subject, 3600);
        $event = new RequestEvent(new AutoBanRequestTestKernel(), $request, HttpKernelInterface::MAIN_REQUEST);
        $event->setResponse(new Response('', 400));

        $this->subscriber($visitorIds, $store, $clock)->onKernelRequest($event);

        self::assertSame(403, $event->getResponse()?->getStatusCode());
        self::assertSame('3600', $event->getResponse()?->headers->get('Retry-After'));
        self::assertTrue($request->attributes->getBoolean(AutoBanRequestSubscriber::PASSIVE_SIGNAL_SKIP_ATTRIBUTE));
    }

    public function testActiveVisitorBanMarksProbeRequestsBeforeRateLimitConsumption(): void
    {
        $clock = new MockClock('2026-06-18 12:00:00');
        $visitorIds = new VisitorIdGenerator('test-secret');
        $store = new AutoBanStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()), clock: $clock);
        $request = Request::create('/.env', server: ['REMOTE_ADDR' => '203.0.113.10']);
        $subject = new AutoBanSubject(AutoBanSubject::VISITOR, $visitorIds->generate($request));
        $store->ban($subject, 3600);
        $event = new RequestEvent(new AutoBanRequestTestKernel(), $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber($visitorIds, $store, $clock)->onKernelRequestProbeCandidate($event);

        self::assertFalse($event->hasResponse());
        self::assertTrue($request->attributes->getBoolean(AutoBanRequestSubscriber::PROBE_RATE_LIMIT_SKIP_ATTRIBUTE));
    }

    public function testIgnorablePathsDoNotBypassActiveBansWhenTheyReachSymfony(): void
    {
        $clock = new MockClock('2026-06-18 12:00:00');
        $visitorIds = new VisitorIdGenerator('test-secret');
        $store = new AutoBanStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()), clock: $clock);
        $request = Request::create('/favicon.ico', server: ['REMOTE_ADDR' => '203.0.113.10']);
        $subject = new AutoBanSubject(AutoBanSubject::VISITOR, $visitorIds->generate($request));
        $store->ban($subject, 3600);
        $event = new RequestEvent(new AutoBanRequestTestKernel(), $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber($visitorIds, $store, $clock)->onKernelRequest($event);

        self::assertSame(403, $event->getResponse()?->getStatusCode());
        self::assertTrue($request->attributes->getBoolean(AutoBanRequestSubscriber::PASSIVE_SIGNAL_SKIP_ATTRIBUTE));
    }

    public function testApiRequestsWithoutTrustedBearerDoNotAuthenticateThroughActiveBans(): void
    {
        $clock = new MockClock('2026-06-18 12:00:00');
        $visitorIds = new VisitorIdGenerator('test-secret');
        $store = new AutoBanStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()), clock: $clock);
        $request = Request::create('/api/v1/status', server: [
            'HTTP_AUTHORIZATION' => 'Bearer invalid.invalid-secret',
            'REMOTE_ADDR' => '203.0.113.10',
        ]);
        $subject = new AutoBanSubject(AutoBanSubject::VISITOR, $visitorIds->generate($request));
        $store->ban($subject, 3600);
        $event = new RequestEvent(new AutoBanRequestTestKernel(), $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber($visitorIds, $store, $clock)->onKernelRequestPreAuthSourceBan($event);

        self::assertSame(403, $event->getResponse()?->getStatusCode());
        self::assertSame('3600', $event->getResponse()?->headers->get('Retry-After'));
        self::assertTrue($request->attributes->getBoolean(AutoBanRequestSubscriber::PASSIVE_SIGNAL_SKIP_ATTRIBUTE));
    }

    public function testApiPreflightsDoNotBypassActiveBans(): void
    {
        $clock = new MockClock('2026-06-18 12:00:00');
        $visitorIds = new VisitorIdGenerator('test-secret');
        $store = new AutoBanStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()), clock: $clock);
        $request = Request::create('/api/v1/admin/settings/general', 'OPTIONS', server: [
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PATCH',
            'HTTP_AUTHORIZATION' => 'Bearer valid-owner-key',
            'HTTP_ORIGIN' => 'https://client.example',
            'REMOTE_ADDR' => '203.0.113.10',
        ]);
        $subject = new AutoBanSubject(AutoBanSubject::VISITOR, $visitorIds->generate($request));
        $store->ban($subject, 3600);
        $event = new RequestEvent(new AutoBanRequestTestKernel(), $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber($visitorIds, $store, $clock)->onKernelRequestPreAuthSourceBan($event);

        self::assertSame(403, $event->getResponse()?->getStatusCode());
        self::assertTrue($request->attributes->getBoolean(AutoBanRequestSubscriber::PASSIVE_SIGNAL_SKIP_ATTRIBUTE));
    }

    public function testSchedulerTriggersWithoutTrustedKeyDoNotBypassActiveBans(): void
    {
        $clock = new MockClock('2026-06-18 12:00:00');
        $visitorIds = new VisitorIdGenerator('test-secret');
        $store = new AutoBanStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()), clock: $clock);
        $request = Request::create('/cron/run', server: [
            'HTTP_AUTHORIZATION' => 'Bearer invalid-scheduler-key',
            'REMOTE_ADDR' => '203.0.113.10',
        ]);
        $subject = new AutoBanSubject(AutoBanSubject::VISITOR, $visitorIds->generate($request));
        $store->ban($subject, 3600);
        $event = new RequestEvent(new AutoBanRequestTestKernel(), $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber($visitorIds, $store, $clock)->onKernelRequestPreAuthSourceBan($event);

        self::assertSame(403, $event->getResponse()?->getStatusCode());
        self::assertTrue($request->attributes->getBoolean(AutoBanRequestSubscriber::PASSIVE_SIGNAL_SKIP_ATTRIBUTE));
    }

    public function testProtectedBrowserSurfacesWithoutPreviousSessionAreBlockedBeforeFirewallAccessControl(): void
    {
        $clock = new MockClock('2026-06-18 12:00:00');
        $visitorIds = new VisitorIdGenerator('test-secret');

        foreach (['/admin', '/editor/content', '/user/profile'] as $path) {
            $store = new AutoBanStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()), clock: $clock);
            $request = Request::create($path, server: ['REMOTE_ADDR' => '203.0.113.10']);
            $subject = new AutoBanSubject(AutoBanSubject::VISITOR, $visitorIds->generate($request));
            $store->ban($subject, 3600);
            $event = new RequestEvent(new AutoBanRequestTestKernel(), $request, HttpKernelInterface::MAIN_REQUEST);

            $this->subscriber($visitorIds, $store, $clock)->onKernelRequestPreAuthBrowserSourceBan($event);

            self::assertSame(403, $event->getResponse()?->getStatusCode(), $path);
            self::assertTrue($request->attributes->getBoolean(AutoBanRequestSubscriber::PASSIVE_SIGNAL_SKIP_ATTRIBUTE), $path);
        }
    }

    public function testProtectedBrowserSurfacesWithPreviousSessionWaitForTrustedAwareGuard(): void
    {
        $clock = new MockClock('2026-06-18 12:00:00');
        $visitorIds = new VisitorIdGenerator('test-secret');
        $store = new AutoBanStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()), clock: $clock);
        $request = Request::create('/admin', server: ['REMOTE_ADDR' => '203.0.113.10']);
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);
        $request->cookies->set($session->getName(), 'previous-session-id');
        $subject = new AutoBanSubject(AutoBanSubject::VISITOR, $visitorIds->generate($request));
        $store->ban($subject, 3600);
        $event = new RequestEvent(new AutoBanRequestTestKernel(), $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber($visitorIds, $store, $clock)->onKernelRequestPreAuthBrowserSourceBan($event);

        self::assertFalse($event->hasResponse());
        self::assertFalse($request->attributes->getBoolean(AutoBanRequestSubscriber::PASSIVE_SIGNAL_SKIP_ATTRIBUTE));
    }

    public function testSecurityHandlerBlocksPreviousSessionNonTrustedBrowserAccess(): void
    {
        $clock = new MockClock('2026-06-18 12:00:00');
        $visitorIds = new VisitorIdGenerator('test-secret');
        $store = new AutoBanStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()), clock: $clock);
        $request = Request::create('/admin', server: ['REMOTE_ADDR' => '203.0.113.10']);
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);
        $request->cookies->set($session->getName(), 'previous-session-id');
        $subject = new AutoBanSubject(AutoBanSubject::VISITOR, $visitorIds->generate($request));
        $store->ban($subject, 3600);
        $tokenStorage = new TokenStorage();
        $user = new UserAccount('99999999-0000-7000-8000-000000000103', 'member', 'member@example.test', 'hash', role: UserRole::User);
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $subscriber = $this->subscriber($visitorIds, $store, $clock, $tokenStorage);
        $handler = new HttpErrorSecurityHandler($this->renderer(), $subscriber);

        $response = $handler->handle($request, new AccessDeniedException('Access denied.'));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('3600', $response->headers->get('Retry-After'));
        self::assertTrue($request->attributes->getBoolean(AutoBanRequestSubscriber::PASSIVE_SIGNAL_SKIP_ATTRIBUTE));
    }

    public function testSecurityHandlerKeepsTrustedBrowserAccessOutsideAutoBanEnforcement(): void
    {
        $clock = new MockClock('2026-06-18 12:00:00');
        $visitorIds = new VisitorIdGenerator('test-secret');
        $store = new AutoBanStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()), clock: $clock);
        $request = Request::create('/admin', server: ['REMOTE_ADDR' => '203.0.113.10']);
        $subject = new AutoBanSubject(AutoBanSubject::VISITOR, $visitorIds->generate($request));
        $store->ban($subject, 3600);
        $tokenStorage = new TokenStorage();
        $user = new UserAccount('99999999-0000-7000-8000-000000000104', 'manager', 'manager@example.test', 'hash', role: UserRole::Manager);
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $subscriber = $this->subscriber($visitorIds, $store, $clock, $tokenStorage);

        self::assertNull($subscriber->responseForSecurityHandler($request));
    }

    public function testErrorResponsesAreOverriddenBeforePassiveSignalScoring(): void
    {
        $clock = new MockClock('2026-06-18 12:00:00');
        $visitorIds = new VisitorIdGenerator('test-secret');

        foreach ([400, 401, 403, 404, 429] as $statusCode) {
            $store = new AutoBanStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()), clock: $clock);
            $request = Request::create('/member-only-page', server: ['REMOTE_ADDR' => '203.0.113.10']);
            $subject = new AutoBanSubject(AutoBanSubject::VISITOR, $visitorIds->generate($request));
            $store->ban($subject, 3600);
            $event = new ResponseEvent(
                new AutoBanRequestTestKernel(),
                $request,
                HttpKernelInterface::MAIN_REQUEST,
                new Response('error response', $statusCode),
            );

            $this->subscriber($visitorIds, $store, $clock)->onKernelResponseErrorStatus($event);

            self::assertSame(403, $event->getResponse()->getStatusCode(), (string) $statusCode);
            self::assertSame('3600', $event->getResponse()->headers->get('Retry-After'), (string) $statusCode);
            self::assertTrue($request->attributes->getBoolean(AutoBanRequestSubscriber::PASSIVE_SIGNAL_SKIP_ATTRIBUTE), (string) $statusCode);
        }
    }

    public function testRecoveryLoginBypassIsReachableDespiteActiveBan(): void
    {
        $clock = new MockClock('2026-06-18 12:00:00');
        $visitorIds = new VisitorIdGenerator('test-secret');
        $store = new AutoBanStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()), clock: $clock);
        $request = Request::create('/de/user/login?bypass=1', server: ['REMOTE_ADDR' => '203.0.113.10']);
        $request->attributes->set('_locale', 'de');
        $subject = new AutoBanSubject(AutoBanSubject::VISITOR, $visitorIds->generate($request));
        $store->ban($subject, 3600);
        $event = new RequestEvent(new AutoBanRequestTestKernel(), $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber($visitorIds, $store, $clock)->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testEarlyLoginGuardBlocksOrdinaryLoginSubmissionsBeforeAuthentication(): void
    {
        $clock = new MockClock('2026-06-18 12:00:00');
        $visitorIds = new VisitorIdGenerator('test-secret');
        $store = new AutoBanStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()), clock: $clock);
        $request = Request::create('/user/login', 'POST', ['username' => 'owner'], server: ['REMOTE_ADDR' => '203.0.113.10']);
        $request->attributes->set('_route', 'user_login');
        $subject = new AutoBanSubject(AutoBanSubject::VISITOR, $visitorIds->generate($request));
        $store->ban($subject, 3600);
        $event = new RequestEvent(new AutoBanRequestTestKernel(), $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber($visitorIds, $store, $clock)->onKernelRequestLogin($event);

        self::assertSame(403, $event->getResponse()?->getStatusCode());
        self::assertTrue($request->attributes->getBoolean(AutoBanRequestSubscriber::PASSIVE_SIGNAL_SKIP_ATTRIBUTE));
    }

    public function testEarlyLoginGuardKeepsMarkedRecoverySubmissionsReachable(): void
    {
        $clock = new MockClock('2026-06-18 12:00:00');
        $visitorIds = new VisitorIdGenerator('test-secret');
        $store = new AutoBanStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()), clock: $clock);
        $csrfTokens = new CsrfTokenManager();
        $request = Request::create('/user/login', 'POST', [
            'username' => 'owner',
            AutoBanRequestSubscriber::RECOVERY_LOGIN_TOKEN_FIELD => (string) $csrfTokens->getToken(AutoBanRequestSubscriber::RECOVERY_LOGIN_TOKEN_ID),
        ], server: ['REMOTE_ADDR' => '203.0.113.10']);
        $request->attributes->set('_route', 'user_login');
        $subject = new AutoBanSubject(AutoBanSubject::VISITOR, $visitorIds->generate($request));
        $store->ban($subject, 3600);
        $event = new RequestEvent(new AutoBanRequestTestKernel(), $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber($visitorIds, $store, $clock, csrfTokens: $csrfTokens)->onKernelRequestLogin($event);

        self::assertFalse($event->hasResponse());
    }

    public function testRecoveryLoginFailuresAfterActiveBanReturnBareForbiddenWithoutSideEffects(): void
    {
        $clock = new MockClock('2026-06-18 12:00:00');
        $visitorIds = new VisitorIdGenerator('test-secret');
        $store = new AutoBanStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()), clock: $clock);
        $csrfTokens = new CsrfTokenManager();
        $request = Request::create('/user/login', 'POST', [
            'username' => 'owner',
            AutoBanRequestSubscriber::RECOVERY_LOGIN_TOKEN_FIELD => (string) $csrfTokens->getToken(AutoBanRequestSubscriber::RECOVERY_LOGIN_TOKEN_ID),
        ], server: ['REMOTE_ADDR' => '203.0.113.10']);
        $request->attributes->set('_route', 'user_login');
        $request->attributes->set(AccessRequestMetadata::REQUEST_ID_ATTRIBUTE, 'request-recovery-failure-ban');
        $subject = new AutoBanSubject(AutoBanSubject::VISITOR, $visitorIds->generate($request));
        $ban = $store->ban($subject, 3600);
        self::assertNotNull($ban);
        $subscriber = $this->subscriber($visitorIds, $store, $clock, csrfTokens: $csrfTokens);
        $requestEvent = new RequestEvent(new AutoBanRequestTestKernel(), $request, HttpKernelInterface::MAIN_REQUEST);

        $subscriber->onKernelRequestLogin($requestEvent);

        self::assertFalse($requestEvent->hasResponse());
        self::assertTrue($request->attributes->getBoolean(AutoBanRequestSubscriber::PASSIVE_SIGNAL_SKIP_ATTRIBUTE));
        self::assertSame($ban->key(), $request->attributes->get(AutoBanRequestSubscriber::RECOVERY_ACTIVE_BAN_KEY_ATTRIBUTE));

        $failure = new LoginFailureEvent(
            new AuthenticationException('Invalid credentials.'),
            new AutoBanRequestTestAuthenticator(),
            $request,
            null,
            'main',
        );
        $subscriber->onLoginFailure($failure);

        self::assertSame(403, $failure->getResponse()?->getStatusCode());
        self::assertSame('3600', $failure->getResponse()?->headers->get('Retry-After'));
        self::assertStringContainsString('request-recovery-failure-ban', (string) $failure->getResponse()?->getContent());
    }

    public function testRecoveryLoginSubmissionsCanEstablishTrustedContextDespiteActiveBan(): void
    {
        $clock = new MockClock('2026-06-18 12:00:00');
        $visitorIds = new VisitorIdGenerator('test-secret');
        $store = new AutoBanStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()), clock: $clock);
        $csrfTokens = new CsrfTokenManager();
        $request = Request::create('/user/login', 'POST', [
            'username' => 'owner',
            AutoBanRequestSubscriber::RECOVERY_LOGIN_TOKEN_FIELD => (string) $csrfTokens->getToken(AutoBanRequestSubscriber::RECOVERY_LOGIN_TOKEN_ID),
        ], server: ['REMOTE_ADDR' => '203.0.113.10']);
        $request->attributes->set('_route', 'user_login');
        $subject = new AutoBanSubject(AutoBanSubject::VISITOR, $visitorIds->generate($request));
        $store->ban($subject, 3600);
        $tokenStorage = new TokenStorage();
        $user = new UserAccount('99999999-0000-7000-8000-000000000101', 'manager', 'manager@example.test', 'hash', role: UserRole::Manager);
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $event = new RequestEvent(new AutoBanRequestTestKernel(), $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber($visitorIds, $store, $clock, $tokenStorage, $csrfTokens)->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testRecoveryLoginSubmissionsWithoutTrustedContextAreRecheckedAfterAuthentication(): void
    {
        $clock = new MockClock('2026-06-18 12:00:00');
        $visitorIds = new VisitorIdGenerator('test-secret');
        $store = new AutoBanStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()), clock: $clock);
        $csrfTokens = new CsrfTokenManager();
        $request = Request::create('/user/login', 'POST', [
            'username' => 'member',
            AutoBanRequestSubscriber::RECOVERY_LOGIN_TOKEN_FIELD => (string) $csrfTokens->getToken(AutoBanRequestSubscriber::RECOVERY_LOGIN_TOKEN_ID),
        ], server: ['REMOTE_ADDR' => '203.0.113.10']);
        $request->attributes->set('_route', 'user_login');
        $subject = new AutoBanSubject(AutoBanSubject::VISITOR, $visitorIds->generate($request));
        $store->ban($subject, 3600);
        $tokenStorage = new TokenStorage();
        $user = new UserAccount('99999999-0000-7000-8000-000000000102', 'member', 'member@example.test', 'hash', role: UserRole::User);
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $event = new RequestEvent(new AutoBanRequestTestKernel(), $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber($visitorIds, $store, $clock, $tokenStorage, $csrfTokens)->onKernelRequest($event);

        self::assertSame(403, $event->getResponse()?->getStatusCode());
        self::assertTrue($request->attributes->getBoolean(AutoBanRequestSubscriber::PASSIVE_SIGNAL_SKIP_ATTRIBUTE));
    }

    public function testOrdinaryLoginSubmissionsDoNotBypassActiveBan(): void
    {
        $clock = new MockClock('2026-06-18 12:00:00');
        $visitorIds = new VisitorIdGenerator('test-secret');
        $store = new AutoBanStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()), clock: $clock);
        $request = Request::create('/user/login', 'POST', ['username' => 'owner'], server: ['REMOTE_ADDR' => '203.0.113.10']);
        $request->attributes->set('_route', 'user_login');
        $subject = new AutoBanSubject(AutoBanSubject::VISITOR, $visitorIds->generate($request));
        $store->ban($subject, 3600);
        $event = new RequestEvent(new AutoBanRequestTestKernel(), $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber($visitorIds, $store, $clock)->onKernelRequest($event);

        self::assertSame(403, $event->getResponse()?->getStatusCode());
    }

    public function testMalformedLoginFieldsDoNotBypassActiveBan(): void
    {
        $clock = new MockClock('2026-06-18 12:00:00');
        $visitorIds = new VisitorIdGenerator('test-secret');
        $store = new AutoBanStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()), clock: $clock);
        $request = Request::create('/user/login', 'POST', [
            'username' => ['owner'],
            AutoBanRequestSubscriber::RECOVERY_LOGIN_TOKEN_FIELD => ['invalid'],
        ], server: ['REMOTE_ADDR' => '203.0.113.10']);
        $request->attributes->set('_route', 'user_login');
        $subject = new AutoBanSubject(AutoBanSubject::VISITOR, $visitorIds->generate($request));
        $store->ban($subject, 3600);
        $event = new RequestEvent(new AutoBanRequestTestKernel(), $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber($visitorIds, $store, $clock)->onKernelRequest($event);

        self::assertSame(403, $event->getResponse()?->getStatusCode());
        self::assertTrue($request->attributes->getBoolean(AutoBanRequestSubscriber::PASSIVE_SIGNAL_SKIP_ATTRIBUTE));
    }

    public function testMalformedRecoveryQueryDoesNotBypassActiveBan(): void
    {
        $clock = new MockClock('2026-06-18 12:00:00');
        $visitorIds = new VisitorIdGenerator('test-secret');
        $store = new AutoBanStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()), clock: $clock);
        $request = Request::create('/user/login?bypass[]=1', server: ['REMOTE_ADDR' => '203.0.113.10']);
        $request->attributes->set('_route', 'user_login');
        $subject = new AutoBanSubject(AutoBanSubject::VISITOR, $visitorIds->generate($request));
        $store->ban($subject, 3600);
        $event = new RequestEvent(new AutoBanRequestTestKernel(), $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber($visitorIds, $store, $clock)->onKernelRequest($event);

        self::assertSame(403, $event->getResponse()?->getStatusCode());
        self::assertTrue($request->attributes->getBoolean(AutoBanRequestSubscriber::PASSIVE_SIGNAL_SKIP_ATTRIBUTE));
    }

    public function testLiveEndpointsDoNotBypassActiveBans(): void
    {
        $clock = new MockClock('2026-06-18 12:00:00');
        $visitorIds = new VisitorIdGenerator('test-secret');
        $store = new AutoBanStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()), clock: $clock);
        $request = Request::create('/api/live/status', server: ['REMOTE_ADDR' => '203.0.113.10']);
        $subject = new AutoBanSubject(AutoBanSubject::VISITOR, $visitorIds->generate($request));
        $store->ban($subject, 3600);
        $event = new RequestEvent(new AutoBanRequestTestKernel(), $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber($visitorIds, $store, $clock)->onKernelRequest($event);

        self::assertSame(403, $event->getResponse()?->getStatusCode());
    }

    public function testPostSignalGuardBlocksBansCreatedAfterTheFinalPreSignalGuard(): void
    {
        $clock = new MockClock('2026-06-18 12:00:00');
        $visitorIds = new VisitorIdGenerator('test-secret');
        $store = new AutoBanStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()), clock: $clock);
        $request = Request::create('/search', 'GET', ['q' => 'probe'], server: ['REMOTE_ADDR' => '203.0.113.10']);
        $subject = new AutoBanSubject(AutoBanSubject::VISITOR, $visitorIds->generate($request));
        $subscriber = $this->subscriber($visitorIds, $store, $clock);
        $event = new RequestEvent(new AutoBanRequestTestKernel(), $request, HttpKernelInterface::MAIN_REQUEST);

        $subscriber->onKernelRequest($event);

        self::assertFalse($event->hasResponse());

        $store->ban($subject, 3600);
        $subscriber->onKernelRequestAfterSignalWrites($event);

        self::assertSame(403, $event->getResponse()?->getStatusCode());
        self::assertTrue($request->attributes->getBoolean(AutoBanRequestSubscriber::PASSIVE_SIGNAL_SKIP_ATTRIBUTE));
    }

    public function testTrustedUsersBypassActiveVisitorBans(): void
    {
        $clock = new MockClock('2026-06-18 12:00:00');
        $visitorIds = new VisitorIdGenerator('test-secret');
        $store = new AutoBanStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()), clock: $clock);
        $request = Request::create('/admin', server: ['REMOTE_ADDR' => '203.0.113.10']);
        $subject = new AutoBanSubject(AutoBanSubject::VISITOR, $visitorIds->generate($request));
        $store->ban($subject, 3600);
        $tokenStorage = new TokenStorage();
        $user = new UserAccount('99999999-0000-7000-8000-000000000001', 'manager', 'manager@example.test', 'hash', role: UserRole::Manager);
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $event = new RequestEvent(new AutoBanRequestTestKernel(), $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber($visitorIds, $store, $clock, $tokenStorage)->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testSubscriberRunsAfterSecurityContextButBeforeOrdinaryRateLimit(): void
    {
        $autoBan = AutoBanRequestSubscriber::getSubscribedEvents()[KernelEvents::REQUEST];
        $autoBanResponse = AutoBanRequestSubscriber::getSubscribedEvents()[KernelEvents::RESPONSE];
        $rateLimit = RateLimitRequestSubscriber::getSubscribedEvents()[KernelEvents::REQUEST];
        $payloadSignals = SuspiciousPayloadSignalSubscriber::getSubscribedEvents()[KernelEvents::REQUEST];
        $passiveSignals = PassiveAbuseSignalSubscriber::getSubscribedEvents()[KernelEvents::RESPONSE];

        self::assertSame(['onKernelRequestPreAuthSourceBan', 4098], $autoBan[0]);
        self::assertSame(['onKernelRequestProbeCandidate', 4097], $autoBan[1]);
        self::assertSame(['onKernelRequestLogin', 16], $autoBan[2]);
        self::assertSame(['onKernelRequestPreAuthBrowserSourceBan', 9], $autoBan[3]);
        self::assertSame(['onKernelRequest', 4], $autoBan[4]);
        self::assertSame(['onKernelRequestAfterSignalWrites', 1], $autoBan[5]);
        self::assertGreaterThan($rateLimit[0][1], $autoBan[0][1]);
        self::assertGreaterThan($rateLimit[0][1], $autoBan[1][1]);
        self::assertGreaterThan(8, $autoBan[3][1]);
        self::assertSame(['onKernelRequestOrdinary', 3], $rateLimit[1]);
        self::assertGreaterThan($rateLimit[1][1], $autoBan[3][1]);
        self::assertGreaterThan($autoBan[5][1], $payloadSignals[1]);
        self::assertLessThan($autoBan[4][1], $payloadSignals[1]);
        self::assertSame(['onKernelResponseErrorStatus', -299], $autoBanResponse);
        self::assertGreaterThan($passiveSignals[1], $autoBanResponse[1]);
    }

    private function subscriber(
        VisitorIdGenerator $visitorIds,
        AutoBanStore $store,
        MockClock $clock,
        ?TokenStorage $tokenStorage = null,
        ?CsrfTokenManager $csrfTokens = null,
    ): AutoBanRequestSubscriber {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) NOT NULL PRIMARY KEY, value CLOB NOT NULL, value_type VARCHAR(32) NOT NULL, sensitive BOOLEAN NOT NULL DEFAULT 0, modified_at DATETIME DEFAULT NULL, modified_by VARCHAR(180) DEFAULT NULL)');
        $config = new Config($connection);
        $config->set(AutoBanPolicy::ENABLED_KEY, AutoBanPolicy::SETUP_ENABLED, ConfigValueType::Boolean);

        return new AutoBanRequestSubscriber(
            new AbuseRequestInspector(
                new AbuseSubjectResolver($visitorIds, $tokenStorage ?? new TokenStorage(), 'test-secret'),
                new RequestIntentClassifier(),
                new ActionCostCatalogue(),
            ),
            new AutoBanPolicy($config),
            $store,
            $this->renderer(),
            new AccessRequestMetadata(),
            clock: $clock,
            csrfTokens: $csrfTokens,
        );
    }

    private function renderer(): HttpErrorRenderer
    {
        return new HttpErrorRenderer(
            new Environment(new ArrayLoader()),
            (new \ReflectionClass(PublishedContentResolver::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(ContentFieldsetRenderer::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(Security::class))->newInstanceWithoutConstructor(),
            new SetupCompletionMarker(),
            new AccessRequestMetadata(),
            sys_get_temp_dir(),
            'test',
        );
    }
}

final class AutoBanRequestTestKernel implements HttpKernelInterface
{
    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        return new Response();
    }
}

final class AutoBanRequestTestAuthenticator implements AuthenticatorInterface
{
    public function supports(Request $request): ?bool
    {
        return true;
    }

    public function authenticate(Request $request): Passport
    {
        throw new AuthenticationException('Not used by this test.');
    }

    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        throw new AuthenticationException('Not used by this test.');
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return null;
    }
}
