<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Extension\ExtensionCookieFacade;
use App\Core\Extension\ExtensionRuntime;
use App\Core\Extension\ExtensionRuntimeContributionRegistry;
use App\Core\Extension\ExtensionRuntimeServices;
use App\Core\Extension\ExtensionScope;
use App\Core\Extension\ExtensionStatus;
use App\Core\Statistics\VisitorIdGenerator;
use App\Entity\Extension;
use App\Privacy\Cookie\CookieConsentDefinition;
use App\Privacy\Cookie\CookieConsentManager;
use App\Privacy\Cookie\CookieConsentRegistry;
use App\Tests\Support\FilesystemTestHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ExtensionRuntimeCookieTest extends TestCase
{
    use FilesystemTestHelper;

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = dirname(__DIR__, 3);
        $this->removeDirectory($this->projectDir.'/extensions/cookie-facade-a');
        $this->removeDirectory($this->projectDir.'/extensions/cookie-facade-b');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir.'/extensions/cookie-facade-a');
        $this->removeDirectory($this->projectDir.'/extensions/cookie-facade-b');
        ExtensionRuntime::reset();
    }

    public function testItReadsAndQueuesNecessaryOwnedCookies(): void
    {
        [$facade, $stack] = $this->configureRuntime([
            'cookie-facade-a' => CookieConsentDefinition::necessary(Cookie::create('cookie_facade_a_state', '', 0, '/module', null, false, true, false, Cookie::SAMESITE_LAX)),
        ]);
        $request = Request::create('/module');
        $request->cookies->set('cookie_facade_a_state', 'old');
        $stack->push($request);
        $this->writeExtensionFile('cookie-facade-a', <<<'PHP'
            <?php

            return [
                extension_cookie_get('cookie_facade_a_state'),
                extension_cookie_set('cookie_facade_a_state', 'new', ['ttl_seconds' => 60]),
            ];
            PHP);

        self::assertSame(['old', true], require $this->projectDir.'/extensions/cookie-facade-a/extension.php');

        $response = $this->flush($facade, $request);
        $cookie = $response->headers->getCookies()[0] ?? null;
        self::assertInstanceOf(Cookie::class, $cookie);
        self::assertSame('cookie_facade_a_state', $cookie->getName());
        self::assertSame('new', $cookie->getValue());
        self::assertSame('/module', $cookie->getPath());
        self::assertTrue($cookie->isHttpOnly());
    }

    public function testItRequiresConsentForOptionalOwnedCookies(): void
    {
        [$facade, $stack, $manager] = $this->configureRuntime([
            'cookie-facade-a' => CookieConsentDefinition::optional(
                Cookie::create('analytics_id', '', 0, '/tracking', null, false, false, false, Cookie::SAMESITE_LAX),
                'Analytics',
                'Measure visits.',
                '/privacy',
            ),
        ]);
        $request = Request::create('/tracking');
        $stack->push($request);
        $this->writeExtensionFile('cookie-facade-a', <<<'PHP'
            <?php

            return extension_cookie_set('analytics_id', 'blocked');
            PHP);
        self::assertFalse(require $this->projectDir.'/extensions/cookie-facade-a/extension.php');

        $consentResponse = new Response();
        $manager->attachConsentCookie($request, $consentResponse, ['analytics_id']);
        $consentedRequest = Request::create('/tracking');
        foreach ($consentResponse->headers->getCookies() as $cookie) {
            $consentedRequest->cookies->set($cookie->getName(), $cookie->getValue());
        }
        $consentedRequest->cookies->set('analytics_id', 'existing');
        $stack->pop();
        $stack->push($consentedRequest);
        $this->writeExtensionFile('cookie-facade-a', <<<'PHP'
            <?php

            return [
                extension_cookie_get('analytics_id'),
                extension_cookie_set('analytics_id', 'allowed'),
            ];
            PHP);

        self::assertSame(['existing', true], require $this->projectDir.'/extensions/cookie-facade-a/extension.php');
        $cookie = $this->flush($facade, $consentedRequest)->headers->getCookies()[0] ?? null;
        self::assertInstanceOf(Cookie::class, $cookie);
        self::assertSame('analytics_id', $cookie->getName());
        self::assertSame('allowed', $cookie->getValue());
        self::assertSame('/tracking', $cookie->getPath());
    }

    public function testItRejectsForeignUnregisteredAndNonExtensionCookieAccess(): void
    {
        [, $stack] = $this->configureRuntime([
            'cookie-facade-a' => CookieConsentDefinition::necessary(Cookie::create('cookie_facade_a_state')),
        ]);
        $stack->push(Request::create('/'));

        self::assertNull(ExtensionRuntime::cookieGet('cookie_facade_a_state'));
        self::assertFalse(ExtensionRuntime::cookieSet('cookie_facade_a_state', 'value'));

        $this->writeExtensionFile('cookie-facade-b', <<<'PHP'
            <?php

            return [
                extension_cookie_get('cookie_facade_a_state'),
                extension_cookie_set('cookie_facade_a_state', 'foreign'),
                extension_cookie_delete('missing_cookie'),
            ];
            PHP);

        self::assertSame([null, false, false], require $this->projectDir.'/extensions/cookie-facade-b/extension.php');
    }

    /**
     * @param array<string, CookieConsentDefinition> $definitionsByExtension
     * @return array{ExtensionCookieFacade, RequestStack, CookieConsentManager}
     */
    private function configureRuntime(array $definitionsByExtension): array
    {
        $registry = new ExtensionRuntimeContributionRegistry();
        foreach ($definitionsByExtension as $extensionName => $definition) {
            $registry->add($this->extension($extensionName), $definition);
        }

        $consent = new CookieConsentManager(
            new CookieConsentRegistry([$registry]),
            new VisitorIdGenerator('cookie-test-secret'),
            'cookie-test-secret',
        );
        $stack = new RequestStack();
        $facade = new ExtensionCookieFacade($stack, $consent, $registry);
        ExtensionRuntime::configure(new ExtensionRuntimeServices($this->projectDir, cookies: $facade));

        return [$facade, $stack, $consent];
    }

    private function extension(string $extensionName): Extension
    {
        return new Extension(
            '77000000-0000-7000-8000-'.substr(hash('sha1', $extensionName), 0, 12),
            [ExtensionScope::Module],
            $extensionName,
            'extensions/'.$extensionName,
            ExtensionStatus::Active,
        );
    }

    private function flush(ExtensionCookieFacade $facade, Request $request): Response
    {
        $response = new Response();
        $facade->flushQueuedCookies(new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        ));

        return $response;
    }

    private function writeExtensionFile(string $extension, string $contents): void
    {
        $this->writeTestFile($this->projectDir, 'extensions/'.$extension.'/extension.php', $contents);
    }
}
