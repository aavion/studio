<?php

declare(strict_types=1);

namespace App\Tests\Security\Captcha;

use App\Core\Extension\ExtensionProviderContribution;
use App\Core\Extension\ExtensionRuntimeContributionRegistry;
use App\Core\Extension\ExtensionScope;
use App\Core\Extension\ExtensionStatus;
use App\Core\Statistics\VisitorIdGenerator;
use App\Entity\Extension;
use App\Security\Captcha\CaptchaInstanceStore;
use App\Security\Captcha\CaptchaProviderBridge;
use App\Security\Captcha\CaptchaRequestGuardSubscriber;
use App\Security\Captcha\CaptchaResult;
use App\Security\Captcha\CaptchaValidationRequest;
use App\Security\Captcha\CaptchaValidationResult;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class CaptchaRequestGuardSubscriberTest extends TestCase
{
    public function testItStripsSpoofedResultAndFailsWhenInstanceIsMissing(): void
    {
        $providerCalled = false;
        $subscriber = $this->subscriber(static function () use (&$providerCalled): CaptchaValidationResult {
            $providerCalled = true;

            return CaptchaValidationResult::verifiedForProvider('demo-captcha');
        });
        $request = Request::create('/user/register', 'POST', [
            CaptchaRequestGuardSubscriber::RESULT_FIELD => 'verified',
        ]);

        $subscriber->onKernelRequest($this->event($request));

        self::assertSame('failed', $request->request->get(CaptchaRequestGuardSubscriber::RESULT_FIELD));
        self::assertSame('failed', $request->attributes->get(CaptchaRequestGuardSubscriber::RESULT_ATTRIBUTE));
        self::assertFalse($providerCalled);
    }

    public function testItTreatsMalformedInternalFieldsAsFailed(): void
    {
        $providerCalled = false;
        $subscriber = $this->subscriber(static function () use (&$providerCalled): CaptchaValidationResult {
            $providerCalled = true;

            return CaptchaValidationResult::verifiedForProvider('demo-captcha');
        });
        $request = Request::create('/user/register', 'POST', [
            CaptchaInstanceStore::INSTANCE_FIELD => ['not' => 'scalar'],
            CaptchaRequestGuardSubscriber::RESULT_FIELD => ['status' => 'verified'],
        ]);

        $subscriber->onKernelRequest($this->event($request));

        self::assertSame('failed', $request->request->get(CaptchaRequestGuardSubscriber::RESULT_FIELD));
        self::assertFalse($providerCalled);
    }

    public function testItSkipsWhenAValidInstanceHasNoActiveProvider(): void
    {
        $store = $this->store();
        $entry = $store->create(Request::create('/user/register'), 'user.registration', 'user-registration-form', 'captcha');
        $subscriber = new CaptchaRequestGuardSubscriber($store, new CaptchaProviderBridge(new ExtensionRuntimeContributionRegistry()));
        $request = Request::create('/user/register', 'POST', [
            CaptchaInstanceStore::INSTANCE_FIELD => $entry->id(),
            'captcha' => ['provider' => 'none'],
        ]);

        $subscriber->onKernelRequest($this->event($request));

        self::assertSame('skipped', $request->request->get(CaptchaRequestGuardSubscriber::RESULT_FIELD));
        self::assertSame('skipped', $request->attributes->get(CaptchaRequestGuardSubscriber::RESULT_ATTRIBUTE));
    }

    public function testItDelegatesValidInstancesToTheActiveProvider(): void
    {
        $store = $this->store();
        $entry = $store->create(Request::create('/user/register'), 'user.registration', 'user-registration-form', 'captcha');
        $subscriber = $this->subscriber(static function (CaptchaValidationRequest $request): CaptchaValidationResult {
            return CaptchaValidationResult::verifiedForProvider('demo-captcha', [
                'payload' => $request->payload(),
            ]);
        }, $store);
        $request = Request::create('/user/register', 'POST', [
            CaptchaInstanceStore::INSTANCE_FIELD => $entry->id(),
            'captcha' => ['token' => 'ok'],
        ]);

        $subscriber->onKernelRequest($this->event($request));

        self::assertSame('verified', $request->request->get(CaptchaRequestGuardSubscriber::RESULT_FIELD));
        self::assertSame('verified', $request->attributes->get(CaptchaRequestGuardSubscriber::RESULT_ATTRIBUTE));
    }

    public function testItAcceptsProviderSkippedAsAServerOwnedResult(): void
    {
        $store = $this->store();
        $entry = $store->create(Request::create('/user/register'), 'user.registration', 'user-registration-form', 'captcha');
        $subscriber = $this->subscriber(static fn (): CaptchaValidationResult => CaptchaValidationResult::skipped('demo-captcha'), $store);
        $request = Request::create('/user/register', 'POST', [
            CaptchaInstanceStore::INSTANCE_FIELD => $entry->id(),
            'captcha' => ['token' => 'provider-owned-skip'],
        ]);

        $subscriber->onKernelRequest($this->event($request));

        self::assertSame('skipped', $request->request->get(CaptchaRequestGuardSubscriber::RESULT_FIELD));
    }

    public function testItFailsAndConsumesInstanceWhenVisitorDoesNotMatch(): void
    {
        $providerCalled = false;
        $store = $this->store();
        $entry = $store->create(Request::create('/user/register', server: ['REMOTE_ADDR' => '127.0.0.1']), 'user.registration', 'user-registration-form', 'captcha');
        $subscriber = $this->subscriber(static function () use (&$providerCalled): CaptchaValidationResult {
            $providerCalled = true;

            return CaptchaValidationResult::verifiedForProvider('demo-captcha');
        }, $store);
        $request = Request::create('/user/register', 'POST', [
            CaptchaInstanceStore::INSTANCE_FIELD => $entry->id(),
        ], server: ['REMOTE_ADDR' => '127.0.0.2']);

        $subscriber->onKernelRequest($this->event($request));

        self::assertSame('failed', $request->request->get(CaptchaRequestGuardSubscriber::RESULT_FIELD));
        self::assertFalse($providerCalled);
        self::assertNull($store->consume($entry->id()));
    }

    public function testItConsumesValidInstancesToPreventReplay(): void
    {
        $store = $this->store();
        $entry = $store->create(Request::create('/user/register'), 'user.registration', 'user-registration-form', 'captcha');
        $subscriber = new CaptchaRequestGuardSubscriber($store, new CaptchaProviderBridge(new ExtensionRuntimeContributionRegistry()));
        $first = Request::create('/user/register', 'POST', [
            CaptchaInstanceStore::INSTANCE_FIELD => $entry->id(),
        ]);
        $second = Request::create('/user/register', 'POST', [
            CaptchaInstanceStore::INSTANCE_FIELD => $entry->id(),
        ]);

        $subscriber->onKernelRequest($this->event($first));
        $subscriber->onKernelRequest($this->event($second));

        self::assertSame('skipped', $first->request->get(CaptchaRequestGuardSubscriber::RESULT_FIELD));
        self::assertSame('failed', $second->request->get(CaptchaRequestGuardSubscriber::RESULT_FIELD));
    }

    public function testItRunsBeforeTheRecoveryLoginCaptchaDecision(): void
    {
        $events = CaptchaRequestGuardSubscriber::getSubscribedEvents();

        self::assertSame(['onKernelRequest', 20], $events[KernelEvents::REQUEST]);
    }

    private function subscriber(callable $provider, ?CaptchaInstanceStore $store = null): CaptchaRequestGuardSubscriber
    {
        $registry = new ExtensionRuntimeContributionRegistry();
        $registry->add($this->extension(), new ExtensionProviderContribution(ExtensionScope::CaptchaProvider, $provider));

        return new CaptchaRequestGuardSubscriber($store ?? $this->store(), new CaptchaProviderBridge($registry));
    }

    private function store(): CaptchaInstanceStore
    {
        return new CaptchaInstanceStore(new ArrayAdapter(), new VisitorIdGenerator('test-secret'), new LockFactory(new InMemoryStore()));
    }

    private function event(Request $request): RequestEvent
    {
        return new RequestEvent(new CaptchaRequestGuardTestKernel(), $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function extension(): Extension
    {
        return new Extension(
            '10000000-0000-7000-8000-000000000707',
            [ExtensionScope::CaptchaProvider],
            'demo-module',
            'extensions/demo-module',
            ExtensionStatus::Active,
        );
    }
}

final class CaptchaRequestGuardTestKernel implements HttpKernelInterface
{
    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): never
    {
        throw new \LogicException('The test kernel should not handle requests.');
    }
}
