<?php

declare(strict_types=1);

namespace App\Tests\View\Alert;

use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Core\Mercure\MercureBinaryManager;
use App\Core\Mercure\MercureRuntime;
use App\Core\Process\DetachedProcessStarter;
use App\Entity\UserAccount;
use App\View\Alert\MercureAvailability;
use App\View\Alert\RequestUiAlertFlasher;
use App\View\Alert\UiAlert;
use App\View\Alert\UiAlertDelivery;
use App\View\Alert\UiAlertDispatcher;
use App\View\Alert\UiAlertInbox;
use App\View\Alert\UiAlertMessageFactory;
use App\View\Alert\UiAlertPublisherInterface;
use App\View\Alert\UiAlertTopicFactory;
use App\View\Alert\UiAlertTranslation;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Translation\IdentityTranslator;

final class UiAlertDispatcherTest extends TestCase
{
    public function testTopicQueueDeliverySkipsMercurePublishWhenUnavailable(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) NOT NULL PRIMARY KEY, value CLOB NOT NULL, value_type VARCHAR(32) NOT NULL, sensitive BOOLEAN NOT NULL DEFAULT 0, modified_at DATETIME DEFAULT NULL, modified_by VARCHAR(180) DEFAULT NULL)');
        $connection->executeStatement('CREATE TABLE ui_alert_inbox (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, topic VARCHAR(255) NOT NULL, payload CLOB NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME DEFAULT NULL)');
        $config = new Config($connection);
        self::assertTrue($config->set(MercureAvailability::ENABLED_KEY, false, ConfigValueType::Boolean));
        $publisher = new RecordingPublisher();
        $topicFactory = new UiAlertTopicFactory('https://studio.example.test', 'test-secret');
        $dispatcher = new UiAlertDispatcher(
            $topicFactory,
            new UiAlertMessageFactory(new IdentityTranslator()),
            new UiAlertInbox($connection),
            $publisher,
            new MercureAvailability(
                $config,
                new MercureRuntime(
                    new MercureBinaryManager('/tmp/studio'),
                    new SilentHub(),
                    'https://studio.example.test',
                    '/tmp/studio',
                ),
                new DetachedProcessStarter(),
                '/tmp/studio',
            ),
            new RequestUiAlertFlasher(new RequestStack()),
            new RequestStack(),
            new Security(new Container()),
        );

        self::assertTrue($dispatcher->addAlertToTopic(
            'https://studio.example.test/ui-alerts/user/test',
            UiAlert::fromLevel('success', 'Queued alert'),
            UiAlertDelivery::Queue,
        ));

        self::assertSame([], $publisher->publishedTopics);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM ui_alert_inbox'));
    }
}

final class RecordingPublisher implements UiAlertPublisherInterface
{
    /**
     * @var list<string>
     */
    public array $publishedTopics = [];

    public function publish(string $topic, UiAlert|\App\Core\Message\Message|UiAlertTranslation $alert, ?string $locale = null, bool $private = false): ?string
    {
        $this->publishedTopics[] = $topic;

        return 'published';
    }

    public function publishToUser(UserAccount|UserInterface|string $user, UiAlert|\App\Core\Message\Message|UiAlertTranslation $alert, ?string $locale = null): ?string
    {
        return $this->publish((string) ($user instanceof UserInterface ? $user->getUserIdentifier() : $user), $alert, $locale);
    }

    public function publishToSession(SessionInterface|string $session, UiAlert|\App\Core\Message\Message|UiAlertTranslation $alert, ?string $locale = null): ?string
    {
        return $this->publish($session instanceof SessionInterface ? $session->getId() : $session, $alert, $locale);
    }
}

final class SilentHub implements HubInterface
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
