<?php

declare(strict_types=1);

namespace App\Tests\Security\RateLimit;

use App\Api\Http\ApiResponder;
use App\Content\Read\PublishedContentResolver;
use App\Content\Render\ContentFieldsetRenderer;
use App\Core\Config\Config;
use App\Core\Log\AccessRequestMetadata;
use App\Core\Message\Message;
use App\Core\Message\MessageReporterInterface;
use App\Core\Routing\IgnorableRequestPathMatcher;
use App\Core\Routing\PathScopeMatcher;
use App\Core\Statistics\VisitorIdGenerator;
use App\Security\Abuse\AbuseRequestInspector;
use App\Security\Abuse\AbuseSubjectResolver;
use App\Security\Abuse\ActionCostCatalogue;
use App\Security\Abuse\RequestIntentClassifier;
use App\Security\Abuse\SuspiciousProbePathMatcher;
use App\Security\AutoBan\AutoBanRequestSubscriber;
use App\Security\RateLimit\RateLimitRequestSubscriber;
use App\Security\RateLimit\RateLimitEnforcer;
use App\Security\RateLimit\RateLimitLimiterFactory;
use App\Security\RateLimit\RateLimitPolicyCatalogue;
use App\Security\RateLimit\RateLimitResponseRenderer;
use App\Security\RateLimit\RateLimitSubjectSelector;
use App\Setup\SetupCompletionMarker;
use App\View\Http\HttpErrorRenderer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Twig\Environment;

final class RateLimitRequestSubscriberTest extends TestCase
{
    private mixed $previousServerValue = null;
    private mixed $previousEnvValue = null;
    private mixed $previousPutenvValue = false;

    protected function setUp(): void
    {
        $this->previousServerValue = $_SERVER[SetupCompletionMarker::KEY] ?? null;
        $this->previousEnvValue = $_ENV[SetupCompletionMarker::KEY] ?? null;
        $this->previousPutenvValue = getenv(SetupCompletionMarker::KEY);
        $_SERVER[SetupCompletionMarker::KEY] = '1';
    }

    protected function tearDown(): void
    {
        unset($_SERVER[SetupCompletionMarker::KEY], $_ENV[SetupCompletionMarker::KEY]);

        if (null !== $this->previousServerValue) {
            $_SERVER[SetupCompletionMarker::KEY] = $this->previousServerValue;
        }

        if (null !== $this->previousEnvValue) {
            $_ENV[SetupCompletionMarker::KEY] = $this->previousEnvValue;
        }

        is_string($this->previousPutenvValue)
            ? putenv(SetupCompletionMarker::KEY.'='.$this->previousPutenvValue)
            : putenv(SetupCompletionMarker::KEY);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function excludedPathCases(): iterable
    {
        yield 'live api root' => ['/api/live', true];
        yield 'live api child' => ['/api/live/status', true];
        yield 'live api sibling' => ['/api/live-status', false];
        yield 'assets child' => ['/assets/app.css', true];
        yield 'assets sibling' => ['/assets-preview', false];
        yield 'build child' => ['/build/app.js', true];
        yield 'build sibling' => ['/builder', false];
        yield 'favicon' => ['/favicon.ico', true];
        yield 'touch icon' => ['/apple-touch-icon.png', true];
        yield 'well-known security' => ['/.well-known/security.txt', true];
        yield 'profiler root' => ['/_profiler', true];
        yield 'profiler child' => ['/_profiler/123', true];
        yield 'profiler sibling' => ['/_profilerfoo', false];
        yield 'toolbar child' => ['/_wdt/123', true];
        yield 'toolbar sibling' => ['/_wdtfoo', false];
    }

    #[DataProvider('excludedPathCases')]
    public function testExcludedPathUsesSegmentBoundaries(string $path, bool $excluded): void
    {
        $subscriber = (new ReflectionClass(RateLimitRequestSubscriber::class))->newInstanceWithoutConstructor();
        $paths = new \ReflectionProperty(RateLimitRequestSubscriber::class, 'paths');
        $paths->setValue($subscriber, new PathScopeMatcher());
        $ignorablePaths = new \ReflectionProperty(RateLimitRequestSubscriber::class, 'ignorablePaths');
        $ignorablePaths->setValue($subscriber, new IgnorableRequestPathMatcher());
        $method = new \ReflectionMethod(RateLimitRequestSubscriber::class, 'excludedRequest');

        self::assertSame($excluded, $method->invoke($subscriber, Request::create($path)));
    }

    public function testExcludedRequestDoesNotUseLocalizedTechnicalPathSegments(): void
    {
        $subscriber = (new ReflectionClass(RateLimitRequestSubscriber::class))->newInstanceWithoutConstructor();
        $paths = new \ReflectionProperty(RateLimitRequestSubscriber::class, 'paths');
        $paths->setValue($subscriber, new PathScopeMatcher());
        $ignorablePaths = new \ReflectionProperty(RateLimitRequestSubscriber::class, 'ignorablePaths');
        $ignorablePaths->setValue($subscriber, new IgnorableRequestPathMatcher());
        $method = new \ReflectionMethod(RateLimitRequestSubscriber::class, 'excludedRequest');
        $localized = Request::create('/de/api/live/status');
        $localized->attributes->set('_locale', 'de');

        self::assertFalse($method->invoke($subscriber, $localized));
        self::assertFalse($method->invoke($subscriber, Request::create('/de/api/live/status')));
    }

    public function testProbePriorityRunsBeforeResponseProducingGates(): void
    {
        $events = RateLimitRequestSubscriber::getSubscribedEvents()[KernelEvents::REQUEST];

        self::assertSame(['onKernelRequestProbe', 4096], $events[0]);
        self::assertGreaterThan(1024, $events[0][1]);
        self::assertGreaterThan(768, $events[0][1]);
        self::assertGreaterThan(512, $events[0][1]);
        self::assertGreaterThan(256, $events[0][1]);
    }

    public function testProbeHookSkipsFullEnforcerForNonProbePaths(): void
    {
        $enforcer = (new ReflectionClass(RateLimitEnforcer::class))->newInstanceWithoutConstructor();
        $responses = (new ReflectionClass(RateLimitResponseRenderer::class))->newInstanceWithoutConstructor();
        $subscriber = new RateLimitRequestSubscriber(
            $enforcer,
            $responses,
            'prod',
            new SetupCompletionMarker(),
            dirname(__DIR__, 3),
            new SuspiciousProbePathMatcher(patterns: SuspiciousProbePathMatcher::DEFAULT_PATTERNS),
        );
        $event = new RequestEvent(
            new RateLimitRequestSubscriberTestKernel(),
            Request::create('/home'),
            HttpKernelInterface::MAIN_REQUEST,
        );

        $subscriber->onKernelRequestProbe($event);

        self::assertFalse($event->hasResponse());
    }

    public function testProbeHookSkipsConsumptionWhenActiveAutoBanAlreadyMatched(): void
    {
        $enforcer = (new ReflectionClass(RateLimitEnforcer::class))->newInstanceWithoutConstructor();
        $responses = (new ReflectionClass(RateLimitResponseRenderer::class))->newInstanceWithoutConstructor();
        $subscriber = new RateLimitRequestSubscriber(
            $enforcer,
            $responses,
            'prod',
            new SetupCompletionMarker(),
            dirname(__DIR__, 3),
            new SuspiciousProbePathMatcher(patterns: SuspiciousProbePathMatcher::DEFAULT_PATTERNS),
        );
        $request = Request::create('/.env');
        $request->attributes->set(AutoBanRequestSubscriber::PROBE_RATE_LIMIT_SKIP_ATTRIBUTE, true);
        $event = new RequestEvent(
            new RateLimitRequestSubscriberTestKernel(),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        $subscriber->onKernelRequestProbe($event);

        self::assertFalse($event->hasResponse());
    }

    public function testProbeHookUsesBareResponseBeforeSetupCompletion(): void
    {
        unset($_SERVER[SetupCompletionMarker::KEY], $_ENV[SetupCompletionMarker::KEY]);
        putenv(SetupCompletionMarker::KEY);
        $subscriber = $this->subscriberWithRealEnforcer();
        $event = new RequestEvent(
            new RateLimitRequestSubscriberTestKernel(),
            Request::create('/.env'),
            HttpKernelInterface::MAIN_REQUEST,
        );

        $subscriber->onKernelRequestProbe($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(Response::HTTP_BAD_REQUEST, $event->getResponse()->getStatusCode());
        self::assertStringContainsString('400 - Bad Request', (string) $event->getResponse()->getContent());
        self::assertStringContainsString('Invalid Request', (string) $event->getResponse()->getContent());
        self::assertStringContainsString('<pre><strong>Request-ID:</strong>', (string) $event->getResponse()->getContent());
        self::assertStringContainsString('no-store', (string) $event->getResponse()->headers->get('Cache-Control'));
    }

    public function testProbeHookUsesForcedBareResponseAfterSetupCompletion(): void
    {
        $subscriber = $this->subscriberWithRealEnforcer();
        $event = new RequestEvent(
            new RateLimitRequestSubscriberTestKernel(),
            Request::create('/.env'),
            HttpKernelInterface::MAIN_REQUEST,
        );

        $subscriber->onKernelRequestProbe($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(Response::HTTP_BAD_REQUEST, $event->getResponse()->getStatusCode());
        self::assertStringContainsString('400 - Bad Request', (string) $event->getResponse()->getContent());
        self::assertStringContainsString('Invalid Request', (string) $event->getResponse()->getContent());
        self::assertStringContainsString('no-store', (string) $event->getResponse()->headers->get('Cache-Control'));
    }

    public function testOrdinaryHookSkipsSetupWizardBeforeSetupCompletion(): void
    {
        unset($_SERVER[SetupCompletionMarker::KEY], $_ENV[SetupCompletionMarker::KEY]);
        putenv(SetupCompletionMarker::KEY);
        $subscriber = $this->subscriberWithUninitializedEnforcer();
        $event = new RequestEvent(
            new RateLimitRequestSubscriberTestKernel(),
            Request::create('/setup/database', 'POST', ['_setup_action' => 'test_database']),
            HttpKernelInterface::MAIN_REQUEST,
        );

        $subscriber->onKernelRequestOrdinary($event);

        self::assertFalse($event->hasResponse());
    }

    public function testSetupApplyRequestIsNotSkippedBeforeSetupCompletion(): void
    {
        $subscriber = (new ReflectionClass(RateLimitRequestSubscriber::class))->newInstanceWithoutConstructor();
        $paths = new \ReflectionProperty(RateLimitRequestSubscriber::class, 'paths');
        $paths->setValue($subscriber, new PathScopeMatcher());
        $method = new \ReflectionMethod(RateLimitRequestSubscriber::class, 'setupApplyRequest');

        self::assertTrue($method->invoke($subscriber, Request::create('/setup/review', 'POST', [
            '_setup_action' => 'apply',
        ])));
        self::assertFalse($method->invoke($subscriber, Request::create('/setup/database', 'POST', [
            '_setup_action' => 'test_database',
        ])));
        self::assertFalse($method->invoke($subscriber, Request::create('/setup/review/extra', 'POST', [
            '_setup_action' => 'apply',
        ])));
        self::assertFalse($method->invoke($subscriber, Request::create('/setup/review', 'GET', [
            '_setup_action' => 'apply',
        ])));
    }

    public function testSetupApplyBeforeSetupCompletionUsesBareTooManyRequestsResponse(): void
    {
        unset($_SERVER[SetupCompletionMarker::KEY], $_ENV[SetupCompletionMarker::KEY]);
        putenv(SetupCompletionMarker::KEY);
        $subscriber = $this->subscriberWithRealEnforcer();
        $event = null;

        for ($i = 0; $i < 6; ++$i) {
            $event = new RequestEvent(
                new RateLimitRequestSubscriberTestKernel(),
                Request::create('/setup/review', 'POST', ['_setup_action' => 'apply'], server: [
                    'REMOTE_ADDR' => '203.0.113.54',
                    'HTTP_USER_AGENT' => 'SetupApplyLimiterTest',
                ]),
                HttpKernelInterface::MAIN_REQUEST,
            );

            $subscriber->onKernelRequestOrdinary($event);
        }

        self::assertNotNull($event);
        self::assertTrue($event->hasResponse());
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $event->getResponse()->getStatusCode());
        self::assertStringContainsString('429 - Too Many Requests', (string) $event->getResponse()->getContent());
        self::assertStringContainsString('retry-after:', (string) $event->getResponse()->getContent());
        self::assertStringContainsString('<pre><strong>Request-ID:</strong>', (string) $event->getResponse()->getContent());
        self::assertStringContainsString('no-store', (string) $event->getResponse()->headers->get('Cache-Control'));
        self::assertNotNull($event->getResponse()->headers->get('Retry-After'));
    }

    private function subscriberWithUninitializedEnforcer(): RateLimitRequestSubscriber
    {
        return new RateLimitRequestSubscriber(
            (new ReflectionClass(RateLimitEnforcer::class))->newInstanceWithoutConstructor(),
            $this->responseRenderer(),
            'prod',
            new SetupCompletionMarker(),
            dirname(__DIR__, 3),
            new SuspiciousProbePathMatcher(patterns: SuspiciousProbePathMatcher::DEFAULT_PATTERNS),
        );
    }

    private function subscriberWithRealEnforcer(): RateLimitRequestSubscriber
    {
        $inspector = new AbuseRequestInspector(
            new AbuseSubjectResolver(new VisitorIdGenerator('test-secret'), new TokenStorage(), 'test-secret'),
            new RequestIntentClassifier(),
            new ActionCostCatalogue(),
        );
        $enforcer = new RateLimitEnforcer(
            $inspector,
            new Config($this->connection()),
            new RateLimitPolicyCatalogue(),
            new RateLimitSubjectSelector(),
            new RateLimitLimiterFactory(new ArrayAdapter()),
            new class implements MessageReporterInterface {
                public function report(Message $message, array $context = []): Message
                {
                    return $message;
                }

                public function reportBatch(iterable $records): array
                {
                    $messages = [];
                    foreach ($records as $record) {
                        $messages[] = $record['message'];
                    }

                    return $messages;
                }
            },
        );

        return new RateLimitRequestSubscriber(
            $enforcer,
            $this->responseRenderer(),
            'prod',
            new SetupCompletionMarker(),
            dirname(__DIR__, 3),
            new SuspiciousProbePathMatcher(patterns: SuspiciousProbePathMatcher::DEFAULT_PATTERNS),
        );
    }

    private function responseRenderer(): RateLimitResponseRenderer
    {
        return new RateLimitResponseRenderer(
            new HttpErrorRenderer(
                (new ReflectionClass(Environment::class))->newInstanceWithoutConstructor(),
                (new ReflectionClass(PublishedContentResolver::class))->newInstanceWithoutConstructor(),
                (new ReflectionClass(ContentFieldsetRenderer::class))->newInstanceWithoutConstructor(),
                (new ReflectionClass(Security::class))->newInstanceWithoutConstructor(),
                new SetupCompletionMarker(),
                new AccessRequestMetadata(),
                dirname(__DIR__, 3),
                'test',
            ),
            (new ReflectionClass(ApiResponder::class))->newInstanceWithoutConstructor(),
            new AccessRequestMetadata(),
        );
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) NOT NULL PRIMARY KEY, value CLOB NOT NULL, value_type VARCHAR(32) NOT NULL, sensitive BOOLEAN NOT NULL DEFAULT 0, modified_at DATETIME DEFAULT NULL, modified_by VARCHAR(180) DEFAULT NULL)');

        return $connection;
    }
}

final class RateLimitRequestSubscriberTestKernel implements HttpKernelInterface
{
    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        return new Response();
    }
}
