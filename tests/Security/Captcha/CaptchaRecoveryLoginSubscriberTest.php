<?php

declare(strict_types=1);

namespace App\Tests\Security\Captcha;

use App\Core\Extension\ExtensionProviderContribution;
use App\Core\Extension\ExtensionRuntimeContributionRegistry;
use App\Core\Extension\ExtensionScope;
use App\Core\Extension\ExtensionStatus;
use App\Entity\Extension;
use App\Security\AutoBan\AutoBanRequestSubscriber;
use App\Security\Captcha\CaptchaFormValidator;
use App\Security\Captcha\CaptchaProviderBridge;
use App\Security\Captcha\CaptchaRecoveryLoginSubscriber;
use App\Security\Captcha\CaptchaValidationRequest;
use App\Security\Captcha\CaptchaValidationResult;
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
        $providerCalled = false;
        $csrfTokens = new CsrfTokenManager();
        $subscriber = $this->subscriber($csrfTokens, static function () use (&$providerCalled): CaptchaValidationResult {
            $providerCalled = true;

            return CaptchaValidationResult::verifiedForProvider('demo-captcha');
        });
        $event = $this->event($this->recoveryLoginRequest($csrfTokens));

        $subscriber->onKernelRequest($event);

        self::assertSame(303, $event->getResponse()?->getStatusCode());
        self::assertSame('/user/login?bypass=1&captcha=failed', $event->getResponse()?->headers->get('Location'));
        self::assertFalse($providerCalled);
    }

    public function testItAllowsRecoveryLoginWhenNoProviderIsActive(): void
    {
        $csrfTokens = new CsrfTokenManager();
        $subscriber = new CaptchaRecoveryLoginSubscriber(
            new CaptchaFormValidator(new CaptchaProviderBridge(new ExtensionRuntimeContributionRegistry())),
            $csrfTokens,
            new CaptchaTestUrlGenerator(),
        );
        $event = $this->event($this->recoveryLoginRequest($csrfTokens));

        $subscriber->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    public function testItAllowsRecoveryLoginWhenProviderVerifiesPayload(): void
    {
        $csrfTokens = new CsrfTokenManager();
        $subscriber = $this->subscriber($csrfTokens, static function (CaptchaValidationRequest $request): CaptchaValidationResult {
            return CaptchaValidationResult::verifiedForProvider('demo-captcha', [
                'payload' => $request->payload(),
            ]);
        });
        $request = $this->recoveryLoginRequest($csrfTokens, [
            'captcha' => ['token' => 'ok'],
        ]);
        $event = $this->event($request);

        $subscriber->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    public function testItIgnoresOrdinaryLoginPosts(): void
    {
        $providerCalled = false;
        $csrfTokens = new CsrfTokenManager();
        $subscriber = $this->subscriber($csrfTokens, static function () use (&$providerCalled): CaptchaValidationResult {
            $providerCalled = true;

            return CaptchaValidationResult::recoverableFailure('demo-captcha');
        });
        $request = Request::create('/user/login', 'POST', [
            'username' => 'demo',
            'password' => 'secret',
        ]);
        $request->attributes->set('_route', 'user_login');
        $event = $this->event($request);

        $subscriber->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
        self::assertFalse($providerCalled);
    }

    public function testItIgnoresInvalidRecoveryTokens(): void
    {
        $providerCalled = false;
        $csrfTokens = new CsrfTokenManager();
        $subscriber = $this->subscriber($csrfTokens, static function () use (&$providerCalled): CaptchaValidationResult {
            $providerCalled = true;

            return CaptchaValidationResult::recoverableFailure('demo-captcha');
        });
        $request = Request::create('/user/login', 'POST', [
            AutoBanRequestSubscriber::RECOVERY_LOGIN_TOKEN_FIELD => 'invalid',
        ]);
        $request->attributes->set('_route', 'user_login');
        $event = $this->event($request);

        $subscriber->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
        self::assertFalse($providerCalled);
    }

    public function testItRunsAfterAutoBanRecoveryMarkerAndBeforeFormLoginCanAuthenticate(): void
    {
        $events = CaptchaRecoveryLoginSubscriber::getSubscribedEvents();

        self::assertSame(['onKernelRequest', 15], $events[KernelEvents::REQUEST]);
    }

    private function subscriber(CsrfTokenManager $csrfTokens, callable $provider): CaptchaRecoveryLoginSubscriber
    {
        $registry = new ExtensionRuntimeContributionRegistry();
        $registry->add($this->extension(), new ExtensionProviderContribution(ExtensionScope::CaptchaProvider, $provider));

        return new CaptchaRecoveryLoginSubscriber(
            new CaptchaFormValidator(new CaptchaProviderBridge($registry)),
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

    private function extension(): Extension
    {
        return new Extension(
            '10000000-0000-7000-8000-000000000706',
            [ExtensionScope::CaptchaProvider],
            'demo-module',
            'extensions/demo-module',
            ExtensionStatus::Active,
        );
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
