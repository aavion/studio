<?php

declare(strict_types=1);

namespace App\Tests\Security\RateLimit;

use App\Security\AutoBan\AutoBanRequestSubscriber;
use App\Security\RateLimit\RateLimitAuthenticationSubscriber;
use App\Security\RateLimit\RateLimitEnforcer;
use App\Security\RateLimit\RateLimitResetService;
use App\Security\RateLimit\RateLimitResponseRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

final class RateLimitAuthenticationSubscriberTest extends TestCase
{
    public function testItSkipsAuthFailureHandlingAfterAutoBanResponse(): void
    {
        $request = Request::create('/api/v1/status', server: [
            'HTTP_AUTHORIZATION' => 'Bearer invalid.invalid',
            'REMOTE_ADDR' => '203.0.113.10',
        ]);
        $request->attributes->set(AutoBanRequestSubscriber::PASSIVE_SIGNAL_SKIP_ATTRIBUTE, true);
        $event = new LoginFailureEvent(
            new AuthenticationException('Invalid credentials.'),
            new RateLimitAuthenticationTestAuthenticator(),
            $request,
            null,
            'api',
        );

        (new RateLimitAuthenticationSubscriber(
            (new \ReflectionClass(RateLimitResetService::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(RateLimitEnforcer::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(RateLimitResponseRenderer::class))->newInstanceWithoutConstructor(),
            'prod',
        ))->onLoginFailure($event);

        self::assertNull($event->getResponse());
    }
}

final class RateLimitAuthenticationTestAuthenticator implements AuthenticatorInterface
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
