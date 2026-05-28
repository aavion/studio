<?php

declare(strict_types=1);

namespace App\Tests\Core\Log;

use App\Core\Access\AccessActor;
use App\Core\Log\AuditAuthenticationSubscriber;
use App\Core\Log\AuditLoggerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

final class AuditAuthenticationSubscriberTest extends TestCase
{
    public function testItWritesFailedLoginAuditEvents(): void
    {
        $logger = new RecordingAuthenticationAuditLogger();
        $request = Request::create('/user/login', 'POST', [], [], [], [
            'HTTP_USER_AGENT' => 'Example Browser',
            'HTTP_X_REQUEST_ID' => 'auth-request-1',
            'REMOTE_ADDR' => '203.0.113.10',
        ]);
        $request->attributes->set('_route', 'user_login');

        (new AuditAuthenticationSubscriber($logger))->onLoginFailure(
            new LoginFailureEvent(
                new AuthenticationException('Invalid credentials.'),
                new AuthenticationSubscriberTestAuthenticator(),
                $request,
                null,
                'main',
            ),
        );

        self::assertCount(1, $logger->records);
        self::assertSame('auth.login_failed', $logger->records[0]['action']);
        self::assertSame('main', $logger->records[0]['context']['firewall']);
        self::assertSame(AuthenticationException::class, $logger->records[0]['context']['reason']);
    }
}

final class AuthenticationSubscriberTestAuthenticator implements AuthenticatorInterface
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

final class RecordingAuthenticationAuditLogger implements AuditLoggerInterface
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
