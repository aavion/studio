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
use App\Security\Abuse\RequestIntentClassifier;
use App\Security\AutoBan\AutoBanPolicy;
use App\Security\AutoBan\AutoBanRequestSubscriber;
use App\Security\AutoBan\AutoBanStore;
use App\Security\AutoBan\AutoBanSubject;
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
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
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
        $event = new RequestEvent(new AutoBanRequestTestKernel(), $request, HttpKernelInterface::MAIN_REQUEST);

        $this->subscriber($visitorIds, $store, $clock, csrfTokens: $csrfTokens)->onKernelRequest($event);

        self::assertNull($event->getResponse());
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
        $rateLimit = RateLimitRequestSubscriber::getSubscribedEvents()[KernelEvents::REQUEST];

        self::assertSame(['onKernelRequest', 4], $autoBan);
        self::assertSame(['onKernelRequestOrdinary', 3], $rateLimit[1]);
        self::assertGreaterThan($rateLimit[1][1], $autoBan[1]);
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
