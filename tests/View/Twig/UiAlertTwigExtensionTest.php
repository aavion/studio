<?php

declare(strict_types=1);

namespace App\Tests\View\Twig;

use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Core\Mercure\MercureBinaryManager;
use App\Core\Mercure\MercureRuntime;
use App\Core\Process\DetachedProcessStarter;
use App\View\Alert\MercureAvailability;
use App\View\Alert\UiAlertTopicFactory;
use App\View\Twig\UiAlertTwigExtension;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubRegistry;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Twig\MercureExtension as MercureTwigExtension;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

final class UiAlertTwigExtensionTest extends TestCase
{
    public function testStorageScopeUsesExistingSessionCookieWithoutStartingSession(): void
    {
        $firstSession = new Session(new MockArraySessionStorage());
        $firstSession->setName('PHPSESSID');
        $firstRequest = Request::create('/admin');
        $firstRequest->setSession($firstSession);
        $firstRequest->cookies->set('PHPSESSID', 'first-session-id');

        $secondSession = new Session(new MockArraySessionStorage());
        $secondSession->setName('PHPSESSID');
        $secondRequest = Request::create('/admin');
        $secondRequest->setSession($secondSession);
        $secondRequest->cookies->set('PHPSESSID', 'second-session-id');

        $firstScope = $this->extension($firstRequest)->storageScope();
        $secondScope = $this->extension($secondRequest)->storageScope();

        self::assertNotSame($firstScope, $secondScope);
        self::assertFalse($firstSession->isStarted());
        self::assertFalse($secondSession->isStarted());
    }

    public function testStreamUrlAuthorizesSubscriptionsForPrivatePushAlerts(): void
    {
        $requestStack = new RequestStack();
        $request = Request::create('https://studio.example.test/admin');
        $requestStack->push($request);
        $tokenFactory = new RecordingMercureTokenFactory();
        $hub = new UiAlertTwigAuthorizedHub($tokenFactory);
        $registry = new HubRegistry($hub);
        $mercure = new MercureTwigExtension($registry, new Authorization($registry), $requestStack);
        $topic = 'urn:system:ui-alerts:user:'.str_repeat('a', 64);

        $url = $this->extension($request, $mercure, true, $requestStack)->streamUrl([$topic]);

        self::assertStringContainsString('topic='.rawurlencode($topic), (string) $url);
        self::assertSame([$topic], $tokenFactory->subscribe);
        self::assertArrayHasKey('', $request->attributes->get('_mercure_authorization_cookies', []));
    }

    public function testStreamUrlDoesNotFailWhenAuthorizationCookieWasAlreadyPrepared(): void
    {
        $requestStack = new RequestStack();
        $request = Request::create('https://studio.example.test/admin');
        $request->attributes->set('_mercure_authorization_cookies', ['' => 'prepared']);
        $requestStack->push($request);
        $tokenFactory = new RecordingMercureTokenFactory();
        $hub = new UiAlertTwigAuthorizedHub($tokenFactory);
        $registry = new HubRegistry($hub);
        $mercure = new MercureTwigExtension($registry, new Authorization($registry), $requestStack);
        $topic = 'urn:system:ui-alerts:user:'.str_repeat('b', 64);

        $url = $this->extension($request, $mercure, true, $requestStack)->streamUrl([$topic]);

        self::assertStringContainsString('topic='.rawurlencode($topic), (string) $url);
        self::assertNull($tokenFactory->subscribe);
    }

    private function extension(
        Request $request,
        ?MercureTwigExtension $mercure = null,
        bool $mercureAvailable = false,
        ?RequestStack $requestStack = null,
    ): UiAlertTwigExtension {
        $requestStack ??= new RequestStack();
        if (null === $requestStack->getMainRequest()) {
            $requestStack->push($request);
        }
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) NOT NULL PRIMARY KEY, value CLOB NOT NULL, value_type VARCHAR(32) NOT NULL, sensitive BOOLEAN NOT NULL DEFAULT 0, modified_at DATETIME DEFAULT NULL, modified_by VARCHAR(180) DEFAULT NULL)');
        $config = new Config($connection);
        $config->set(MercureAvailability::AVAILABLE_KEY, $mercureAvailable, ConfigValueType::Boolean);

        return new UiAlertTwigExtension(
            $requestStack,
            new Security($this->securityContainer()),
            new UiAlertTopicFactory('topic-secret'),
            new MercureAvailability(
                $config,
                new MercureRuntime(
                    new MercureBinaryManager('/tmp/studio'),
                    new UiAlertTwigSilentHub(),
                    'https://studio.example.test',
                    '/tmp/studio',
                ),
                new DetachedProcessStarter(),
                '/tmp/studio',
            ),
            'storage-secret',
            $mercure,
        );
    }

    private function securityContainer(): Container
    {
        $container = new Container();
        $container->set('security.token_storage', new TokenStorage());

        return $container;
    }
}

final class RecordingMercureTokenFactory implements TokenFactoryInterface
{
    /**
     * @var list<string>|null
     */
    public ?array $subscribe = null;

    /**
     * @var list<string>|null
     */
    public ?array $publish = null;

    public function create(?array $subscribe = [], ?array $publish = [], array $additionalClaims = []): string
    {
        $this->subscribe = $subscribe;
        $this->publish = $publish;

        return 'jwt-token';
    }
}

final readonly class UiAlertTwigAuthorizedHub implements HubInterface
{
    public function __construct(private TokenFactoryInterface $tokenFactory)
    {
    }

    public function getPublicUrl(): string
    {
        return 'https://studio.example.test/.well-known/mercure';
    }

    public function getFactory(): ?TokenFactoryInterface
    {
        return $this->tokenFactory;
    }

    public function publish(Update $update): string
    {
        return 'published';
    }
}

final class UiAlertTwigSilentHub implements HubInterface
{
    public function getPublicUrl(): string
    {
        return 'https://studio.example.test/.well-known/mercure';
    }

    public function getFactory(): ?TokenFactoryInterface
    {
        return null;
    }

    public function publish(Update $update): string
    {
        return 'published';
    }
}
