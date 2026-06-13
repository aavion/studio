<?php

declare(strict_types=1);

namespace App\Tests\View\Alert;

use App\Core\Message\Message;
use App\Core\Message\CommonMessageCode;
use App\Tests\Support\IdentityTranslator;
use App\View\Alert\MercureUiAlertPublisher;
use App\View\Alert\UiAlert;
use App\View\Alert\UiAlertTopicFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Update;

final class MercureUiAlertPublisherTest extends TestCase
{
    public function testItPublishesUiAlertPayloadsAsPrivateMercureUpdates(): void
    {
        $hub = new RecordingHub();
        $publisher = new MercureUiAlertPublisher(
            $hub,
            new UiAlertTopicFactory('https://example.test', 'secret'),
            new IdentityTranslator(),
        );

        $id = $publisher->publish('https://example.test/ui-alerts/session/topic', UiAlert::fromLevel('danger', 'Saved'));

        self::assertSame('update-id', $id);
        self::assertInstanceOf(Update::class, $hub->update);
        self::assertSame(['https://example.test/ui-alerts/session/topic'], $hub->update->getTopics());
        self::assertTrue($hub->update->isPrivate());
        self::assertSame('ui-alert', $hub->update->getType());
        self::assertSame([
            'message' => 'Saved',
            'level' => 'error',
            'persistent' => false,
            'mode' => 'auto',
            'loading' => false,
        ], json_decode($hub->update->getData(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testItTranslatesStructuredMessagesBeforePublishing(): void
    {
        $hub = new RecordingHub();
        $publisher = new MercureUiAlertPublisher(
            $hub,
            new UiAlertTopicFactory('https://example.test', 'secret'),
            new IdentityTranslator(),
        );

        $publisher->publishToSession('session-id', Message::success('message.package.discovery_completed', ['%package%' => 'Demo']));

        $payload = json_decode($hub->update?->getData() ?? '{}', true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('message.package.discovery_completed', $payload['message']);
        self::assertSame('success', $payload['level']);
        self::assertSame(CommonMessageCode::SUCCESS, $payload['code']);
        self::assertSame('message.package.discovery_completed', $payload['translation_key']);
    }
}

final class RecordingHub implements HubInterface
{
    public ?Update $update = null;

    public function getPublicUrl(): string
    {
        return 'https://example.test/.well-known/mercure';
    }

    public function getFactory(): ?TokenFactoryInterface
    {
        return null;
    }

    public function publish(Update $update): string
    {
        $this->update = $update;

        return 'update-id';
    }
}
