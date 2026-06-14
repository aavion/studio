<?php

declare(strict_types=1);

namespace App\Tests\View\Twig;

use App\Core\Config\Config;
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
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
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

    private function extension(Request $request): UiAlertTwigExtension
    {
        $requestStack = new RequestStack();
        $requestStack->push($request);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) NOT NULL PRIMARY KEY, value CLOB NOT NULL, value_type VARCHAR(32) NOT NULL, sensitive BOOLEAN NOT NULL DEFAULT 0, modified_at DATETIME DEFAULT NULL, modified_by VARCHAR(180) DEFAULT NULL)');

        return new UiAlertTwigExtension(
            $requestStack,
            new Security($this->securityContainer()),
            new UiAlertTopicFactory('topic-secret'),
            new MercureAvailability(
                new Config($connection),
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
        );
    }

    private function securityContainer(): Container
    {
        $container = new Container();
        $container->set('security.token_storage', new TokenStorage());

        return $container;
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
