<?php

declare(strict_types=1);

namespace App\Tests\Security\Captcha;

use App\Security\AutoBan\AutoBanRequestSubscriber;
use App\Security\Captcha\CaptchaFormValidator;
use App\Security\Captcha\CaptchaRequestGuardSubscriber;
use App\Security\Captcha\CaptchaRecoveryLoginSubscriber;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Security\Csrf\CsrfTokenManager;

final class CaptchaRecoveryLoginSubscriberTest extends TestCase
{
    public function testItRedirectsRecoveryLoginWhenRequiredCaptchaPayloadIsMissing(): void
    {
        $csrfTokens = new CsrfTokenManager();
        $subscriber = $this->subscriber($csrfTokens);
        $event = $this->event($this->recoveryLoginRequest($csrfTokens));

        $subscriber->onKernelRequest($event);

        self::assertSame(303, $event->getResponse()?->getStatusCode());
        self::assertSame('/user/login?bypass=1&captcha=failed', $event->getResponse()?->headers->get('Location'));
    }

    public function testItPreservesSafeReturnTargetWhenRecoveryCaptchaFails(): void
    {
        $csrfTokens = new CsrfTokenManager();
        $subscriber = $this->subscriber($csrfTokens);
        $event = $this->event($this->recoveryLoginRequest($csrfTokens, [
            '_target_path' => '/admin/settings',
        ]));

        $subscriber->onKernelRequest($event);

        self::assertSame('/user/login?bypass=1&captcha=failed&return_to=%2Fadmin%2Fsettings', $event->getResponse()?->headers->get('Location'));
    }

    public function testItDropsUnsafeReturnTargetWhenRecoveryCaptchaFails(): void
    {
        $csrfTokens = new CsrfTokenManager();
        $subscriber = $this->subscriber($csrfTokens);
        $event = $this->event($this->recoveryLoginRequest($csrfTokens, [
            '_target_path' => '//example.test/admin',
        ]));

        $subscriber->onKernelRequest($event);

        self::assertSame('/user/login?bypass=1&captcha=failed', $event->getResponse()?->headers->get('Location'));
    }

    public function testItAllowsRecoveryLoginWhenCaptchaWasSkippedServerSide(): void
    {
        $csrfTokens = new CsrfTokenManager();
        $subscriber = $this->subscriber($csrfTokens);
        $request = $this->recoveryLoginRequest($csrfTokens);
        $request->attributes->set(CaptchaRequestGuardSubscriber::RESULT_ATTRIBUTE, 'skipped');
        $event = $this->event($request);

        $subscriber->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    public function testItAllowsRecoveryLoginWhenCaptchaWasVerifiedServerSide(): void
    {
        $csrfTokens = new CsrfTokenManager();
        $subscriber = $this->subscriber($csrfTokens);
        $request = $this->recoveryLoginRequest($csrfTokens);
        $request->attributes->set(CaptchaRequestGuardSubscriber::RESULT_ATTRIBUTE, 'verified');
        $event = $this->event($request);

        $subscriber->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    public function testItIgnoresOrdinaryLoginPosts(): void
    {
        $csrfTokens = new CsrfTokenManager();
        $subscriber = $this->subscriber($csrfTokens);
        $request = Request::create('/user/login', 'POST', [
            'username' => 'demo',
            'password' => 'secret',
        ]);
        $request->attributes->set('_route', 'user_login');
        $event = $this->event($request);

        $subscriber->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    public function testItIgnoresInvalidRecoveryTokens(): void
    {
        $csrfTokens = new CsrfTokenManager();
        $subscriber = $this->subscriber($csrfTokens);
        $request = Request::create('/user/login', 'POST', [
            AutoBanRequestSubscriber::RECOVERY_LOGIN_TOKEN_FIELD => 'invalid',
        ]);
        $request->attributes->set('_route', 'user_login');
        $event = $this->event($request);

        $subscriber->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    public function testItRunsAfterAutoBanRecoveryMarkerAndBeforeFormLoginCanAuthenticate(): void
    {
        $events = CaptchaRecoveryLoginSubscriber::getSubscribedEvents();

        self::assertSame(['onKernelRequest', 15], $events[KernelEvents::REQUEST]);
    }

    private function subscriber(CsrfTokenManager $csrfTokens): CaptchaRecoveryLoginSubscriber
    {
        return new CaptchaRecoveryLoginSubscriber(
            new CaptchaFormValidator(),
            $csrfTokens,
            new CaptchaTestUrlGenerator(),
        );
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function recoveryLoginRequest(CsrfTokenManager $csrfTokens, array $fields = []): Request
    {
        $request = Request::create('/user/login', 'POST', array_replace([
            AutoBanRequestSubscriber::RECOVERY_LOGIN_TOKEN_FIELD => $csrfTokens->getToken(AutoBanRequestSubscriber::RECOVERY_LOGIN_TOKEN_ID)->getValue(),
        ], $fields));
        $request->attributes->set('_route', 'user_login');

        return $request;
    }

    private function event(Request $request): RequestEvent
    {
        return new RequestEvent(new CaptchaRecoveryLoginTestKernel(), $request, HttpKernelInterface::MAIN_REQUEST);
    }

}

final class CaptchaRecoveryLoginTestKernel implements HttpKernelInterface
{
    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): never
    {
        throw new \LogicException('The test kernel should not handle requests.');
    }
}

final class CaptchaTestUrlGenerator implements UrlGeneratorInterface
{
    public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
    {
        Assert::assertSame('user_login', $name);

        return '/user/login?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    public function setContext(RequestContext $context): void
    {
    }

    public function getContext(): RequestContext
    {
        return new RequestContext();
    }
}
