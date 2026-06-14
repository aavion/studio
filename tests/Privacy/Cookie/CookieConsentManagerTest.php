<?php

declare(strict_types=1);

namespace App\Tests\Privacy\Cookie;

use App\Core\Statistics\FileVisitorIdentityStore;
use App\Core\Statistics\VisitorIdGenerator;
use App\Privacy\Cookie\ConsentCookieJar;
use App\Privacy\Cookie\CookieConsentDefinition;
use App\Privacy\Cookie\CookieConsentManager;
use App\Privacy\Cookie\CookieConsentProviderInterface;
use App\Privacy\Cookie\CookieConsentRegistry;
use App\Privacy\Cookie\CookieConsentResponseSubscriber;
use App\Privacy\Cookie\CookieConsentTwigExtension;
use App\Privacy\Cookie\CoreCookieConsentProvider;
use App\Tests\Support\FilesystemTestHelper;
use App\Controller\CookieConsentController;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class CookieConsentManagerTest extends TestCase
{
    use FilesystemTestHelper;

    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = $this->createTemporaryDirectory('cookie-consent-visitors');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->cacheDir);
    }

    public function testItRequiresBannerOnlyForOptionalCookiesWithoutStoredConsent(): void
    {
        $manager = $this->manager([$this->provider([
            CookieConsentDefinition::necessary(Cookie::create('PHPSESSID')),
        ])]);

        self::assertFalse($manager->bannerRequired(Request::create('/')));

        $optionalManager = $this->manager([$this->provider([
            CookieConsentDefinition::optional(Cookie::create('analytics_id'), 'Analytics', 'Measure visits.', 'https://example.test/privacy'),
        ])]);

        self::assertTrue($optionalManager->bannerRequired(Request::create('/')));
    }

    public function testItDefaultsOptionalCookiesOffWhenDoNotTrackIsEnabled(): void
    {
        $request = Request::create('/');
        $request->headers->set('DNT', '1');

        self::assertFalse($this->manager()->defaultOptionalSelected($request));
    }

    public function testItAllowsOptionalCookiesAfterConsentCookieWasStored(): void
    {
        $definition = CookieConsentDefinition::optional(
            Cookie::create('analytics_id'),
            'Analytics',
            'Measure visits.',
            'https://example.test/privacy',
        );
        $manager = $this->manager([$this->provider([$definition])]);
        $request = Request::create('/');
        $response = new Response();

        $manager->attachConsentCookie($request, $response, ['analytics_id']);
        $cookie = $response->headers->getCookies()[0] ?? null;
        self::assertInstanceOf(Cookie::class, $cookie);

        $nextRequest = Request::create('/');
        $nextRequest->cookies->set($cookie->getName(), $cookie->getValue());

        self::assertTrue($manager->allowed($nextRequest, $definition));
        self::assertFalse($manager->bannerRequired($nextRequest));
    }

    public function testItIgnoresTamperedConsentCookies(): void
    {
        $definition = CookieConsentDefinition::optional(
            Cookie::create('analytics_id'),
            'Analytics',
            'Measure visits.',
            'https://example.test/privacy',
        );
        $manager = $this->manager([$this->provider([$definition])]);
        $request = Request::create('/');
        $response = new Response();

        $manager->attachConsentCookie($request, $response, ['analytics_id']);
        $cookie = $response->headers->getCookies()[0] ?? null;
        self::assertInstanceOf(Cookie::class, $cookie);

        $nextRequest = Request::create('/');
        $nextRequest->cookies->set($cookie->getName(), $cookie->getValue().'tampered');

        self::assertFalse($manager->allowed($nextRequest, $definition));
        self::assertTrue($manager->bannerRequired($nextRequest));
    }

    public function testItIgnoresExpiredConsentCookies(): void
    {
        $definition = CookieConsentDefinition::optional(
            Cookie::create('analytics_id'),
            'Analytics',
            'Measure visits.',
            'https://example.test/privacy',
        );
        $manager = $this->manager([$this->provider([$definition])]);
        $request = Request::create('/');
        $request->cookies->set(CookieConsentManager::CONSENT_COOKIE_NAME, $this->signedConsentCookie([
            'accepted' => ['analytics_id'],
            'created_at' => time() - 31_536_001,
            'version' => 1,
        ]));

        self::assertFalse($manager->allowed($request, $definition));
        self::assertTrue($manager->bannerRequired($request));
    }

    public function testItExpiresWithdrawnOptionalCookies(): void
    {
        $definition = CookieConsentDefinition::optional(
            Cookie::create('analytics_id', 'value', 0, '/tracking'),
            'Analytics',
            'Measure visits.',
            'https://example.test/privacy',
        );
        $manager = $this->manager([$this->provider([$definition])]);
        $request = Request::create('/');
        $acceptedResponse = new Response();
        $manager->attachConsentCookie($request, $acceptedResponse, ['analytics_id']);
        $consentCookie = $acceptedResponse->headers->getCookies()[0] ?? null;
        self::assertInstanceOf(Cookie::class, $consentCookie);

        $withdrawRequest = Request::create('/');
        $withdrawRequest->cookies->set($consentCookie->getName(), $consentCookie->getValue());
        $withdrawResponse = new Response();
        $manager->attachConsentCookie($withdrawRequest, $withdrawResponse, []);

        $expired = array_values(array_filter(
            $withdrawResponse->headers->getCookies(),
            static fn (Cookie $cookie): bool => 'analytics_id' === $cookie->getName(),
        ));

        self::assertCount(1, $expired);
        self::assertSame('/tracking', $expired[0]->getPath());
        self::assertLessThan(time(), $expired[0]->getExpiresTime());
    }

    public function testItExpiresRejectedOptionalCookiesWithoutStoredConsent(): void
    {
        $definition = CookieConsentDefinition::optional(
            Cookie::create('analytics_id', 'value', 0, '/tracking'),
            'Analytics',
            'Measure visits.',
            'https://example.test/privacy',
        );
        $manager = $this->manager([$this->provider([$definition])]);
        $request = Request::create('/');
        $request->cookies->set('analytics_id', 'legacy-value');
        $response = new Response();

        $manager->attachConsentCookie($request, $response, []);

        $expired = array_values(array_filter(
            $response->headers->getCookies(),
            static fn (Cookie $cookie): bool => 'analytics_id' === $cookie->getName(),
        ));

        self::assertCount(1, $expired);
        self::assertSame('/tracking', $expired[0]->getPath());
        self::assertLessThan(time(), $expired[0]->getExpiresTime());
    }

    public function testResponseSubscriberKeepsOptionalCookieClearHeaders(): void
    {
        $definition = CookieConsentDefinition::optional(
            Cookie::create('analytics_id', 'value', 0, '/tracking'),
            'Analytics',
            'Measure visits.',
            'https://example.test/privacy',
        );
        $registry = new CookieConsentRegistry([$this->provider([$definition])]);
        $manager = $this->manager([$this->provider([$definition])]);
        $request = Request::create('/');
        $response = new Response();
        $manager->attachConsentCookie($request, $response, []);

        (new CookieConsentResponseSubscriber($registry, $manager))->filterCookies(new ResponseEvent(
            new NullKernel(),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        ));

        $expired = array_values(array_filter(
            $response->headers->getCookies(),
            static fn (Cookie $cookie): bool => 'analytics_id' === $cookie->getName(),
        ));

        self::assertCount(1, $expired);
        self::assertLessThan(time(), $expired[0]->getExpiresTime());
    }

    public function testResponseSubscriberRunsAfterCookieWriters(): void
    {
        $subscription = CookieConsentResponseSubscriber::getSubscribedEvents()[\Symfony\Component\HttpKernel\KernelEvents::RESPONSE] ?? null;

        self::assertIsArray($subscription);
        self::assertSame('filterCookies', $subscription[0] ?? null);
        self::assertLessThanOrEqual(-4096, $subscription[1] ?? 0);
    }

    public function testResponseSubscriberRemovesActiveOptionalCookiesWithoutConsent(): void
    {
        $definition = CookieConsentDefinition::optional(
            Cookie::create('analytics_id', 'value', 0, '/tracking'),
            'Analytics',
            'Measure visits.',
            'https://example.test/privacy',
        );
        $registry = new CookieConsentRegistry([$this->provider([$definition])]);
        $manager = $this->manager([$this->provider([$definition])]);
        $request = Request::create('/');
        $response = new Response();
        $response->headers->setCookie(Cookie::create('analytics_id', 'value', 0, '/tracking'));

        (new CookieConsentResponseSubscriber($registry, $manager))->filterCookies(new ResponseEvent(
            new NullKernel(),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        ));

        self::assertSame([], array_values(array_filter(
            $response->headers->getCookies(),
            static fn (Cookie $cookie): bool => 'analytics_id' === $cookie->getName(),
        )));
    }

    public function testItRejectsDuplicateCookieDefinitions(): void
    {
        $registry = new CookieConsentRegistry([
            $this->provider([CookieConsentDefinition::necessary(Cookie::create('PHPSESSID'))]),
            $this->provider([CookieConsentDefinition::optional(
                Cookie::create('PHPSESSID'),
                'Other',
                'Override the session cookie.',
                'https://example.test/privacy',
            )]),
        ]);

        $this->expectException(LogicException::class);
        $registry->definitions();
    }

    #[DataProvider('unsafePrivacyUrls')]
    public function testItRejectsUnsafeOptionalCookiePrivacyUrls(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);

        CookieConsentDefinition::optional(
            Cookie::create('analytics_id'),
            'Analytics',
            'Measure visits.',
            $url,
        );
    }

    public function testItAcceptsHttpAndRelativeOptionalCookiePrivacyUrls(): void
    {
        foreach (['https://example.test/privacy', 'http://example.test/privacy', '/privacy', './privacy', '../privacy', 'privacy'] as $url) {
            self::assertSame($url, CookieConsentDefinition::optional(
                Cookie::create('analytics_id'),
                'Analytics',
                'Measure visits.',
                $url,
            )->privacyUrl());
        }
    }

    public function testItReturnsSelectedOptionalNamesFromStoredConsentOrDefaults(): void
    {
        $definition = CookieConsentDefinition::optional(
            Cookie::create('analytics_id'),
            'Analytics',
            'Measure visits.',
            'https://example.test/privacy',
        );
        $manager = $this->manager([$this->provider([$definition])]);
        $request = Request::create('/');

        self::assertSame(['analytics_id'], $manager->selectedOptionalNames($request));

        $requestWithDnt = Request::create('/');
        $requestWithDnt->headers->set('DNT', '1');

        self::assertSame([], $manager->selectedOptionalNames($requestWithDnt));

        $response = new Response();
        $manager->attachConsentCookie($request, $response, []);
        $cookie = $response->headers->getCookies()[0] ?? null;
        self::assertInstanceOf(Cookie::class, $cookie);

        $nextRequest = Request::create('/');
        $nextRequest->cookies->set($cookie->getName(), $cookie->getValue());

        self::assertSame([], $manager->selectedOptionalNames($nextRequest));
    }

    public function testCookieConsentRejectActionIgnoresPostedOptionalCookies(): void
    {
        $definition = CookieConsentDefinition::optional(
            Cookie::create('analytics_id'),
            'Analytics',
            'Measure visits.',
            'https://example.test/privacy',
        );
        $manager = $this->manager([$this->provider([$definition])]);
        $controller = new CookieConsentController($manager);
        $request = Request::create('/privacy/cookie-consent', 'POST', [
            '_cookie_consent_target_path' => '/',
            '_cookie_consent_action' => 'reject_optional',
            'cookies' => ['analytics_id'],
        ]);
        $request->request->set('_csrf_token', $manager->csrfToken($request));

        $response = $controller->store($request);
        $cookie = $response->headers->getCookies()[0] ?? null;
        self::assertInstanceOf(Cookie::class, $cookie);

        $nextRequest = Request::create('/');
        $nextRequest->cookies->set($cookie->getName(), $cookie->getValue());

        self::assertFalse($manager->allowed($nextRequest, $definition));
    }

    public function testCookieConsentRedirectsOnlyToSafeRelativeTargets(): void
    {
        $manager = $this->manager();
        $controller = new CookieConsentController($manager);

        foreach (['https://evil.example.test', '//evil.example.test/path', '/\\evil.example.test/path', "/privacy\nLocation: https://evil.example.test", 'relative/path', ''] as $target) {
            $request = Request::create('/privacy/cookie-consent', 'POST', [
                '_cookie_consent_target_path' => $target,
                '_cookie_consent_action' => 'reject_optional',
            ]);
            $request->request->set('_csrf_token', $manager->csrfToken($request));

            self::assertSame('/', $controller->store($request)->headers->get('Location'));
        }

        $request = Request::create('/privacy/cookie-consent', 'POST', [
            '_cookie_consent_target_path' => '/privacy',
            '_cookie_consent_action' => 'reject_optional',
        ]);
        $request->request->set('_csrf_token', $manager->csrfToken($request));

        self::assertSame('/privacy', $controller->store($request)->headers->get('Location'));
    }

    public function testConsentCookieUsesSystemOwnedName(): void
    {
        self::assertSame('system_cookie_consent', CookieConsentManager::CONSENT_COOKIE_NAME);
    }

    public function testCookieConsentCsrfTokenIsVisitorBound(): void
    {
        $manager = $this->manager();
        $firstRequest = Request::create('/privacy/cookie-consent', 'POST', server: [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_USER_AGENT' => 'Studio Browser/1.0',
        ]);
        $secondRequest = Request::create('/privacy/cookie-consent', 'POST', server: [
            'REMOTE_ADDR' => '198.51.100.24',
            'HTTP_USER_AGENT' => 'Other Browser/2.0',
        ]);

        $token = $manager->csrfToken($firstRequest);

        self::assertTrue($manager->validCsrfToken($firstRequest, $token));
        self::assertFalse($manager->validCsrfToken($secondRequest, $token));
    }

    public function testConsentCookieJarBlocksOptionalCookiesWithoutConsent(): void
    {
        $definition = CookieConsentDefinition::optional(
            Cookie::create('analytics_id', 'value'),
            'Analytics',
            'Measure visits.',
            'https://example.test/privacy',
        );
        $jar = new ConsentCookieJar($this->manager([$this->provider([$definition])]));
        $request = Request::create('/');
        $response = new Response();

        self::assertFalse($jar->set($request, $response, $definition));
        self::assertSame([], $response->headers->getCookies());
    }

    public function testConsentCookieJarRejectsCustomCookiesWithDifferentIdentity(): void
    {
        $definition = CookieConsentDefinition::optional(
            Cookie::create('analytics_id', 'value', 0, '/tracking', 'example.test'),
            'Analytics',
            'Measure visits.',
            'https://example.test/privacy',
        );
        $manager = $this->manager([$this->provider([$definition])]);
        $request = Request::create('/');
        $consentResponse = new Response();
        $manager->attachConsentCookie($request, $consentResponse, ['analytics_id']);
        $consentCookie = $consentResponse->headers->getCookies()[0] ?? null;
        self::assertInstanceOf(Cookie::class, $consentCookie);

        $requestWithConsent = Request::create('/');
        $requestWithConsent->cookies->set($consentCookie->getName(), $consentCookie->getValue());
        $jar = new ConsentCookieJar($manager);

        foreach ([
            Cookie::create('other_cookie', 'value', 0, '/tracking', 'example.test'),
            Cookie::create('analytics_id', 'value', 0, '/other', 'example.test'),
            Cookie::create('analytics_id', 'value', 0, '/tracking', 'other.example.test'),
            Cookie::create('analytics_id', 'value', 0, '/tracking', 'example.test', true),
            Cookie::create('analytics_id', 'value', 0, '/tracking', 'example.test', false, false),
            Cookie::create('analytics_id', 'value', 0, '/tracking', 'example.test', false, true, false, Cookie::SAMESITE_STRICT),
        ] as $cookie) {
            $response = new Response();

            self::assertFalse($jar->set($requestWithConsent, $response, $definition, $cookie));
            self::assertSame([], $response->headers->getCookies());
        }

        $response = new Response();
        self::assertTrue($jar->set(
            $requestWithConsent,
            $response,
            $definition,
            Cookie::create('analytics_id', 'updated', 0, '/tracking', 'example.test'),
        ));
        self::assertCount(1, $response->headers->getCookies());
    }

    public function testTwigExtensionExposesConsentTriggerAttributes(): void
    {
        $extension = new CookieConsentTwigExtension(new RequestStack(), new CookieConsentRegistry([]), $this->manager());

        self::assertSame([
            'aria-controls' => 'cookie-consent',
            'data-cookie-consent-open' => true,
        ], $extension->triggerAttributes());
    }

    public function testCoreProviderRegistersOnlyNecessaryCookies(): void
    {
        $definitions = (new CoreCookieConsentProvider())->cookieConsentDefinitions();

        self::assertNotSame([], $definitions);
        self::assertSame([], array_values(array_filter(
            $definitions,
            static fn (CookieConsentDefinition $definition): bool => !$definition->isNecessary(),
        )));
    }

    /**
     * @param iterable<CookieConsentProviderInterface> $providers
     */
    private function manager(iterable $providers = []): CookieConsentManager
    {
        return new CookieConsentManager(
            new CookieConsentRegistry($providers),
            new VisitorIdGenerator('test-secret', new FileVisitorIdentityStore($this->cacheDir, 'test')),
            'test-secret',
        );
    }

    /**
     * @param list<CookieConsentDefinition> $definitions
     */
    private function provider(array $definitions): CookieConsentProviderInterface
    {
        return new class($definitions) implements CookieConsentProviderInterface {
            public function __construct(private array $definitions)
            {
            }

            public function cookieConsentDefinitions(): array
            {
                return $this->definitions;
            }
        };
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafePrivacyUrls(): iterable
    {
        yield 'javascript scheme' => ['javascript:alert(1)'];
        yield 'data scheme' => ['data:text/html,<script>alert(1)</script>'];
        yield 'protocol relative' => ['//evil.example.test/privacy'];
        yield 'backslash redirect' => ['/\\evil.example.test/privacy'];
        yield 'http without host' => ['http:/privacy'];
        yield 'control character' => ["https://example.test/privacy\njavascript:alert(1)"];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function signedConsentCookie(array $payload): string
    {
        $body = rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');

        return $body.'.'.hash_hmac('sha256', 'privacy-cookie-consent|'.$body, 'test-secret');
    }
}

final class NullKernel implements HttpKernelInterface
{
    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        return new Response();
    }
}
