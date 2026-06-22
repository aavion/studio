<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Extension\ExtensionAlertFacade;
use App\Core\Extension\ExtensionMessageKey;
use App\Core\Extension\ExtensionRuntime;
use App\Core\Extension\ExtensionRuntimeServices;
use App\Core\Message\Message;
use App\Entity\AclGroup;
use App\Entity\UserAccount;
use App\Security\UserRole;
use App\Tests\Support\FilesystemTestHelper;
use App\View\Alert\UiAlert;
use App\View\Alert\UiAlertDelivery;
use App\View\Alert\UiAlertDeliveryOptions;
use App\View\Alert\UiAlertDispatcherInterface;
use App\View\Alert\UiAlertPresentation;
use App\View\Alert\UiAlertTopicFactory;
use App\View\Alert\UiAlertTranslation;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class ExtensionRuntimeAlertTest extends TestCase
{
    use FilesystemTestHelper;

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = dirname(__DIR__, 3);
        $this->removeDirectory($this->projectDir.'/extensions/alert-facade');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir.'/extensions/alert-facade');
        ExtensionRuntime::reset();
    }

    public function testItReturnsFalseForNonExtensionCallers(): void
    {
        $dispatcher = new RecordingExtensionAlertDispatcher();
        ExtensionRuntime::configure(new ExtensionRuntimeServices($this->projectDir, alerts: new ExtensionAlertFacade($dispatcher, new UiAlertTopicFactory('secret'))));

        self::assertFalse(ExtensionRuntime::alert('info', 'message.extension.discovery_completed'));
        self::assertSame([], $dispatcher->records);
    }

    public function testItRoutesExtensionAlertsToSupportedTargets(): void
    {
        $dispatcher = new RecordingExtensionAlertDispatcher();
        $topics = new UiAlertTopicFactory('secret');
        $group = new AclGroup('72000000-0000-7000-8000-000000000001', 'team_admin', 'Team Admin', UserRole::User->accessLevel());
        $topic = $topics->roleTopic(UserRole::Manager);
        ExtensionRuntime::configure(new ExtensionRuntimeServices(
            $this->projectDir,
            alerts: new ExtensionAlertFacade($dispatcher, $topics, $this->entityManagerForGroup($group)),
        ));
        $this->writeExtensionFile(sprintf(<<<'PHP'
            <?php

            return [
                extension_alert('success', 'ext.alert-facade.runtime.ready', ['%%count%%' => 1]),
                extension_alert('warning', 'Literal alert', [], ['target' => 'session', 'session' => 'session-id']),
                extension_alert('info', 'ext.alert-facade.runtime.ready', ['%%count%%' => 2], ['target' => 'user', 'uid' => '71000000-0000-7000-8000-000000000001']),
                extension_alert('error', 'ext.alert-facade.runtime.ready', ['%%count%%' => 3], ['target' => 'role', 'role' => 'admin']),
                extension_alert('info', 'ext.alert-facade.runtime.ready', ['%%count%%' => 4], ['target' => 'topic', 'topic' => %s]),
                extension_alert('info', 'ext.alert-facade.runtime.ready', ['%%count%%' => 5], ['target' => 'acl_group', 'identifier' => 'team_admin']),
                extension_alert('info', 'ext.alert-facade.runtime.ready', ['%%count%%' => 6], ['target' => 'acl_group', 'uid' => '72000000-0000-7000-8000-000000000001']),
                extension_alert('info', 'ext.alert-facade.runtime.ready', [], ['target' => 'topic', 'topic' => 'https://example.test/topic']),
                extension_alert('info', 'ext.alert-facade.runtime.ready', [], ['target' => 'acl_group', 'identifier' => 'missing']),
                extension_alert('info', 'ext.other.runtime.ready'),
                extension_alert('info', 'message.extension.discovery_completed'),
            ];
            PHP, var_export($topic, true)));

        self::assertSame([true, true, true, true, true, true, true, false, false, true, true], require $this->projectDir.'/extensions/alert-facade/extension.php');
        self::assertCount(9, $dispatcher->records);
        self::assertSame('current', $dispatcher->records[0]['target']);
        self::assertTrue($dispatcher->records[0]['delivery']->flashes());
        self::assertSame('ext.alert-facade.runtime.ready', $dispatcher->records[0]['alert']->translationKey());
        self::assertSame('session', $dispatcher->records[1]['target']);
        self::assertSame('session-id', $dispatcher->records[1]['id']);
        self::assertTrue($dispatcher->records[1]['delivery']->queues());
        self::assertSame(ExtensionMessageKey::EXTENSION_RUNTIME_LOG, $dispatcher->records[1]['alert']->translationKey());
        self::assertSame(['%message%' => 'Literal alert'], $dispatcher->records[1]['alert']->parameters());
        self::assertSame('user', $dispatcher->records[2]['target']);
        self::assertSame('71000000-0000-7000-8000-000000000001', $dispatcher->records[2]['id']);
        self::assertSame('topic', $dispatcher->records[3]['target']);
        self::assertSame($topics->roleTopic(UserRole::Admin), $dispatcher->records[3]['id']);
        self::assertSame('topic', $dispatcher->records[4]['target']);
        self::assertSame($topic, $dispatcher->records[4]['id']);
        self::assertSame($topics->aclGroupTopic($group), $dispatcher->records[5]['id']);
        self::assertSame($topics->aclGroupTopic($group), $dispatcher->records[6]['id']);
        self::assertSame(ExtensionMessageKey::EXTENSION_RUNTIME_LOG, $dispatcher->records[7]['alert']->translationKey());
        self::assertSame(['%message%' => 'ext.other.runtime.ready'], $dispatcher->records[7]['alert']->parameters());
        self::assertSame(ExtensionMessageKey::EXTENSION_RUNTIME_LOG, $dispatcher->records[8]['alert']->translationKey());
        self::assertSame(['%message%' => 'message.extension.discovery_completed'], $dispatcher->records[8]['alert']->parameters());
    }

    private function writeExtensionFile(string $contents): void
    {
        $this->writeTestFile($this->projectDir, 'extensions/alert-facade/extension.php', $contents);
    }

    private function entityManagerForGroup(AclGroup $group): EntityManagerInterface
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->once())->method('find')->willReturnCallback(
            static fn (mixed $id): ?AclGroup => strtolower((string) $id) === $group->uid() ? $group : null,
        );
        $repository->expects($this->exactly(2))->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?AclGroup => ($criteria['identifier'] ?? null) === $group->identifier() ? $group : null,
        );
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->exactly(3))->method('getRepository')->with(AclGroup::class)->willReturn($repository);

        return $entityManager;
    }
}

final class RecordingExtensionAlertDispatcher implements UiAlertDispatcherInterface
{
    /**
     * @var list<array{target: string, id?: string, alert: UiAlertTranslation|UiAlert|Message, delivery: UiAlertDeliveryOptions, presentation: UiAlertPresentation|null}>
     */
    public array $records = [];

    public function addAlert(
        UiAlert|Message|UiAlertTranslation $alert,
        UiAlertDelivery|UiAlertDeliveryOptions $delivery = UiAlertDelivery::Direct,
        ?UiAlertPresentation $presentation = null,
    ): bool {
        $this->records[] = [
            'target' => 'current',
            'alert' => $alert,
            'delivery' => $this->options($delivery),
            'presentation' => $presentation,
        ];

        return true;
    }

    public function addAlertToTopic(
        string $topic,
        UiAlert|Message|UiAlertTranslation $alert,
        UiAlertDelivery|UiAlertDeliveryOptions $delivery = UiAlertDelivery::Queue,
        ?UiAlertPresentation $presentation = null,
    ): bool {
        $this->records[] = [
            'target' => 'topic',
            'id' => $topic,
            'alert' => $alert,
            'delivery' => $this->options($delivery),
            'presentation' => $presentation,
        ];

        return true;
    }

    public function addAlertToUser(
        UserAccount|UserInterface|string $user,
        UiAlert|Message|UiAlertTranslation $alert,
        UiAlertDelivery|UiAlertDeliveryOptions $delivery = UiAlertDelivery::Queue,
        ?UiAlertPresentation $presentation = null,
    ): bool {
        $this->records[] = [
            'target' => 'user',
            'id' => is_string($user) ? $user : $user->getUserIdentifier(),
            'alert' => $alert,
            'delivery' => $this->options($delivery),
            'presentation' => $presentation,
        ];

        return true;
    }

    public function addAlertToSession(
        SessionInterface|string $session,
        UiAlert|Message|UiAlertTranslation $alert,
        UiAlertDelivery|UiAlertDeliveryOptions $delivery = UiAlertDelivery::Queue,
        ?UiAlertPresentation $presentation = null,
    ): bool {
        $this->records[] = [
            'target' => 'session',
            'id' => is_string($session) ? $session : $session->getId(),
            'alert' => $alert,
            'delivery' => $this->options($delivery),
            'presentation' => $presentation,
        ];

        return true;
    }

    private function options(UiAlertDelivery|UiAlertDeliveryOptions $delivery): UiAlertDeliveryOptions
    {
        return $delivery instanceof UiAlertDeliveryOptions ? $delivery : $delivery->toOptions();
    }
}
