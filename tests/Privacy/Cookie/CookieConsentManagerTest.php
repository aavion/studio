<?php

declare(strict_types=1);

namespace App\Tests\Privacy\Cookie;

use App\Privacy\Cookie\ConsentCookieJar;
use App\Privacy\Cookie\CookieConsentDefinition;
use App\Privacy\Cookie\CookieConsentManager;
use App\Privacy\Cookie\CookieConsentProviderInterface;
use App\Privacy\Cookie\CookieConsentRegistry;
use App\Privacy\Cookie\CookieConsentTwigExtension;
use App\Privacy\Cookie\CoreCookieConsentProvider;
use App\Controller\CookieConsentController;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

final class CookieConsentManagerTest extends TestCase
{
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
            '_csrf_token' => $manager->csrfToken(),
            '_cookie_consent_target_path' => '/',
            '_cookie_consent_action' => 'reject_optional',
            'cookies' => ['analytics_id'],
        ]);

        $response = $controller->store($request);
        $cookie = $response->headers->getCookies()[0] ?? null;
        self::assertInstanceOf(Cookie::class, $cookie);

        $nextRequest = Request::create('/');
        $nextRequest->cookies->set($cookie->getName(), $cookie->getValue());

        self::assertFalse($manager->allowed($nextRequest, $definition));
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
        return new CookieConsentManager(new CookieConsentRegistry($providers), 'test-secret');
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
}
