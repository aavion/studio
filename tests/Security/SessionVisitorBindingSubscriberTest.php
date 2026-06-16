<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Core\Access\AccessActor;
use App\Core\Log\AccessRequestMetadata;
use App\Core\Log\AuditLoggerInterface;
use App\Core\Log\DatabaseLogRetentionPolicy;
use App\Core\Statistics\VisitorIdGenerator;
use App\Entity\UserAccount;
use App\Security\Abuse\AbuseRequestInspector;
use App\Security\Abuse\AbuseSubjectResolver;
use App\Security\Abuse\ActionCostCatalogue;
use App\Security\Abuse\RequestIntentClassifier;
use App\Security\Abuse\SecuritySignalRecorder;
use App\Security\SessionVisitorBindingSubscriber;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

final class SessionVisitorBindingSubscriberTest extends TestCase
{
    public function testItBindsTheCurrentVisitorOnLoginSuccess(): void
    {
        $tokenStorage = new TokenStorage();
        $auditLogger = new RecordingSessionAuditLogger();
        $generator = new VisitorIdGenerator('test-secret');
        $request = Request::create('/user/login', 'POST');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $user = $this->user();

        (new SessionVisitorBindingSubscriber($tokenStorage, $generator, $auditLogger))->onLoginSuccess(
            new LoginSuccessEvent(
                new SessionBindingTestAuthenticator(),
                new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn () => $user)),
                new UsernamePasswordToken($user, 'main', $user->getRoles()),
                $request,
                new Response(),
                'main',
            ),
        );

        self::assertSame($generator->generate($request), $request->getSession()->get(SessionVisitorBindingSubscriber::SESSION_VISITOR_ID));
        self::assertFalse($request->getSession()->has(SessionVisitorBindingSubscriber::SESSION_VISITOR_CHANGE_COUNT));
        self::assertSame([], $auditLogger->records);
    }

    public function testItTerminatesSessionsWhenTheBoundVisitorChanges(): void
    {
        $tokenStorage = new TokenStorage();
        $user = $this->user();
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $auditLogger = new RecordingSessionAuditLogger();
        $generator = new VisitorIdGenerator('test-secret');
        $request = Request::create('/admin');
        $session = new Session(new MockArraySessionStorage());
        $session->set(SessionVisitorBindingSubscriber::SESSION_VISITOR_ID, 'previousVisitorId1234');
        $request->setSession($session);

        $event = new RequestEvent(new SessionBindingTestKernel(), $request, HttpKernelInterface::MAIN_REQUEST);

        (new SessionVisitorBindingSubscriber($tokenStorage, $generator, $auditLogger))->onKernelRequest($event);

        $currentVisitorId = $generator->generate($request);
        self::assertTrue($event->hasResponse());
        self::assertSame(303, $event->getResponse()?->getStatusCode());
        self::assertSame('/user/login', $event->getResponse()?->headers->get('Location'));
        self::assertNull($tokenStorage->getToken());
        self::assertFalse($session->has(SessionVisitorBindingSubscriber::SESSION_VISITOR_ID));
        self::assertCount(1, $auditLogger->records);
        self::assertSame('auth.session_visitor_mismatch_terminated', $auditLogger->records[0]['action']);
        self::assertSame('previousVisitorId1234', $auditLogger->records[0]['context']['previous_visitor_id']);
        self::assertSame($currentVisitorId, $auditLogger->records[0]['context']['current_visitor_id']);
    }

    public function testItRecordsSecuritySignalWhenTheBoundVisitorChanges(): void
    {
        $tokenStorage = new TokenStorage();
        $user = $this->user();
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $auditLogger = new RecordingSessionAuditLogger();
        $generator = new VisitorIdGenerator('test-secret');
        $request = Request::create('/admin', server: ['REMOTE_ADDR' => '203.0.113.42']);
        $request->attributes->set(AccessRequestMetadata::REQUEST_ID_ATTRIBUTE, 'request-session-mismatch');
        $session = new Session(new MockArraySessionStorage());
        $session->set(SessionVisitorBindingSubscriber::SESSION_VISITOR_ID, 'previousVisitorId1234');
        $request->setSession($session);
        $connection = $this->signalConnection();

        $event = new RequestEvent(new SessionBindingTestKernel(), $request, HttpKernelInterface::MAIN_REQUEST);

        (new SessionVisitorBindingSubscriber(
            $tokenStorage,
            $generator,
            $auditLogger,
            new AbuseRequestInspector(
                new AbuseSubjectResolver($generator, $tokenStorage, 'test-secret'),
                new RequestIntentClassifier(),
                new ActionCostCatalogue(),
            ),
            new SecuritySignalRecorder($connection, new DatabaseLogRetentionPolicy($connection)),
            new AccessRequestMetadata(),
        ))->onKernelRequest($event);

        $row = $connection->fetchAssociative('SELECT * FROM security_signal_event');
        self::assertIsArray($row);
        self::assertSame('session', $row['signal_type']);
        self::assertSame('security.signal.session_visitor_mismatch', $row['reason_code']);
        self::assertSame('ERROR', $row['severity']);
        self::assertSame(90, (int) $row['confidence']);
        self::assertSame('user', $row['subject_type']);
        self::assertSame($user->uid(), $row['subject_identifier']);
        self::assertSame('request-session-mismatch', $row['request_id']);
        self::assertSame($generator->generate($request), $row['visitor_id']);

        $context = json_decode((string) $row['context'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('previousVisitorId1234', $context['previous_visitor_id']);
        self::assertSame($generator->generate($request), $context['current_visitor_id']);
        self::assertSame(1, $context['change_count']);
        self::assertIsString($context['ip_bucket']);
        self::assertNotSame('', $context['ip_bucket']);
    }

    public function testItKeepsSessionsWhenTheBoundVisitorMatches(): void
    {
        $tokenStorage = new TokenStorage();
        $user = $this->user();
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $auditLogger = new RecordingSessionAuditLogger();
        $generator = new VisitorIdGenerator('test-secret');
        $firstRequest = Request::create('/admin');
        $firstResponse = new Response();
        $generator->attachCookie($firstRequest, $firstResponse);
        $cookie = $firstResponse->headers->getCookies()[0] ?? null;

        self::assertNotNull($cookie);

        $session = new Session(new MockArraySessionStorage());
        $session->set(SessionVisitorBindingSubscriber::SESSION_VISITOR_ID, $generator->generate($firstRequest));
        $nextRequest = Request::create('/admin');
        $nextRequest->cookies->set(VisitorIdGenerator::COOKIE_NAME, $cookie->getValue());
        $nextRequest->setSession($session);
        $event = new RequestEvent(new SessionBindingTestKernel(), $nextRequest, HttpKernelInterface::MAIN_REQUEST);

        (new SessionVisitorBindingSubscriber($tokenStorage, $generator, $auditLogger))->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
        self::assertNotNull($tokenStorage->getToken());
        self::assertSame($generator->generate($nextRequest), $session->get(SessionVisitorBindingSubscriber::SESSION_VISITOR_ID));
        self::assertSame([], $auditLogger->records);
    }

    private function user(): UserAccount
    {
        return new UserAccount(
            '10000000-0000-7000-8000-000000000001',
            'admin',
            'admin@example.test',
            'hash',
        );
    }

    private function signalConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) PRIMARY KEY NOT NULL, value CLOB NOT NULL, value_type VARCHAR(255) NOT NULL, sensitive BOOLEAN NOT NULL, modified_at DATETIME NOT NULL, modified_by VARCHAR(180) DEFAULT NULL)');
        $connection->executeStatement('CREATE TABLE security_signal_event (uid VARCHAR(36) PRIMARY KEY NOT NULL, occurred_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, signal_type VARCHAR(80) NOT NULL, reason_code VARCHAR(120) NOT NULL, severity VARCHAR(16) NOT NULL, confidence INTEGER NOT NULL, subject_type VARCHAR(40) NOT NULL, subject_identifier VARCHAR(190) NOT NULL, ip_derived BOOLEAN NOT NULL, request_family VARCHAR(40) NOT NULL, request_intent VARCHAR(80) NOT NULL, request_id VARCHAR(64) NOT NULL, visitor_id VARCHAR(64) NOT NULL, path VARCHAR(1024) NOT NULL, route VARCHAR(190) NOT NULL, http_status INTEGER DEFAULT NULL, context CLOB NOT NULL)');

        return $connection;
    }
}

final class RecordingSessionAuditLogger implements AuditLoggerInterface
{
    /**
     * @var list<array{actor: AccessActor, action: string, context: array<string, mixed>}>
     */
    public array $records = [];

    public function log(AccessActor $actor, string $action, array $context = []): void
    {
        $this->records[] = [
            'actor' => $actor,
            'action' => $action,
            'context' => $context,
        ];
    }
}

final class SessionBindingTestKernel implements HttpKernelInterface
{
    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        return new Response();
    }
}

final class SessionBindingTestAuthenticator implements AuthenticatorInterface
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
