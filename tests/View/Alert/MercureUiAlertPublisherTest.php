<?php

declare(strict_types=1);

namespace App\Tests\View\Alert;

use App\Core\Message\Message;
use App\Core\Message\CommonMessageCode;
use App\Tests\Support\IdentityTranslator;
use App\View\Alert\MercureUiAlertPublisher;
use App\View\Alert\UiAlert;
use App\View\Alert\UiAlertMessageFactory;
use App\View\Alert\UiAlertTopicFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Update;

final class MercureUiAlertPublisherTest extends TestCase
{
    public function testItPublishesUiAlertPayloadsAsPublicMercureUpdatesByDefault(): void
    {
        $hub = new RecordingHub();
        $publisher = $this->publisher($hub);

        $id = $publisher->publish('urn:system:ui-alerts:session:topic', UiAlert::fromLevel('danger', 'Saved'));

        self::assertSame('update-id', $id);
        self::assertInstanceOf(Update::class, $hub->update);
        self::assertSame(['urn:system:ui-alerts:session:topic'], $hub->update->getTopics());
        self::assertFalse($hub->update->isPrivate());
        self::assertNull($hub->update->getId());
        self::assertSame('ui-alert', $hub->update->getType());
        self::assertSame([
            'message' => 'Saved',
            'level' => 'error',
            'persistent' => false,
            'mode' => 'auto',
            'loading' => false,
        ], json_decode($hub->update->getData(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testItCanPublishPrivateMercureUpdatesExplicitly(): void
    {
        $hub = new RecordingHub();
        $publisher = $this->publisher($hub);

        $publisher->publish('urn:system:ui-alerts:session:topic', UiAlert::fromLevel('success', 'Saved'), private: true);

        self::assertTrue($hub->update?->isPrivate());
    }

    public function testItUsesStableAlertIdsAsMercureEventIds(): void
    {
        $hub = new RecordingHub();
        $publisher = $this->publisher($hub);

        $publisher->publish('urn:system:ui-alerts:session:topic', UiAlert::fromLevel('success', 'Saved', id: 'ui-alert-stable'));

        self::assertSame('ui-alert-stable', $hub->update?->getId());
    }

    public function testItTranslatesStructuredMessagesBeforePublishing(): void
    {
        $hub = new RecordingHub();
        $publisher = $this->publisher($hub);

        $publisher->publishToSession('session-id', Message::success('message.package.discovery_completed', ['%package%' => 'Demo']));

        $payload = json_decode($hub->update?->getData() ?? '{}', true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('message.package.discovery_completed', $payload['message']);
        self::assertSame('success', $payload['level']);
        self::assertSame(CommonMessageCode::SUCCESS, $payload['code']);
        self::assertSame('message.package.discovery_completed', $payload['translation_key']);
        self::assertArrayNotHasKey('context', $payload);
    }

    private function publisher(RecordingHub $hub): MercureUiAlertPublisher
    {
        return new MercureUiAlertPublisher(
            $hub,
            new UiAlertTopicFactory('secret'),
            new UiAlertMessageFactory(new IdentityTranslator()),
        );
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
