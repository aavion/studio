<?php

declare(strict_types=1);

namespace App\Tests\Security\AutoBan;

use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Core\Message\Message;
use App\Entity\UserAccount;
use App\Security\AutoBan\ActiveAutoBan;
use App\Security\AutoBan\AutoBanOwnerAlertNotifier;
use App\Security\AutoBan\AutoBanPolicy;
use App\Security\UserAccountStatus;
use App\Security\UserRole;
use App\View\Alert\UiAlert;
use App\View\Alert\UiAlertDelivery;
use App\View\Alert\UiAlertDeliveryOptions;
use App\View\Alert\UiAlertDispatcherInterface;
use App\View\Alert\UiAlertPresentation;
use App\View\Alert\UiAlertTranslation;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class AutoBanOwnerAlertNotifierTest extends TestCase
{
    public function testItQueuesHiddenOwnerAlertsForNewBans(): void
    {
        $connection = $this->connection();
        $connection->insert('user_account', [
            'uid' => 'owner-uid',
            'role' => UserRole::Owner->value,
            'status' => UserAccountStatus::Active->value,
        ]);
        $connection->insert('user_account', [
            'uid' => 'admin-uid',
            'role' => UserRole::Admin->value,
            'status' => UserAccountStatus::Active->value,
        ]);
        $alerts = new RecordingAutoBanAlertDispatcher();
        $notifier = new AutoBanOwnerAlertNotifier(new AutoBanPolicy(new Config($connection)), $connection, $alerts);
        $ban = $this->ban();

        $notifier->notifyBanTriggered($ban);

        self::assertCount(1, $alerts->userAlerts);
        self::assertSame('owner-uid', $alerts->userAlerts[0]['user']);
        self::assertSame(UiAlertDelivery::Queue, $alerts->userAlerts[0]['delivery']);
        self::assertInstanceOf(UiAlertTranslation::class, $alerts->userAlerts[0]['alert']);
        self::assertSame('admin.auto_bans.alerts.triggered', $alerts->userAlerts[0]['alert']->translationKey());
        self::assertSame('hidden', $alerts->userAlerts[0]['presentation']?->mode());
        self::assertSame('auto-ban-triggered-'.$ban->key(), $alerts->userAlerts[0]['presentation']?->id());
        self::assertSame([
            ['label' => 'Review', 'href' => '/admin/security/auto-bans'],
        ], $alerts->userAlerts[0]['presentation']?->actions());
    }

    public function testItSkipsOwnerAlertsWhenDeliveryIsDisabled(): void
    {
        $connection = $this->connection();
        $connection->insert('user_account', [
            'uid' => 'owner-uid',
            'role' => UserRole::Owner->value,
            'status' => UserAccountStatus::Active->value,
        ]);
        $config = new Config($connection);
        $config->set(AutoBanPolicy::NEW_BAN_OWNER_ALERTS_KEY, false, ConfigValueType::Boolean);
        $alerts = new RecordingAutoBanAlertDispatcher();
        $notifier = new AutoBanOwnerAlertNotifier(new AutoBanPolicy($config), $connection, $alerts);

        $notifier->notifyBanTriggered($this->ban());

        self::assertSame([], $alerts->userAlerts);
    }

    private function ban(): ActiveAutoBan
    {
        return new ActiveAutoBan(
            'abc123abc123abc123abc123abc123abc123abcd',
            'visitor',
            'visitor-alert',
            DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', '2026-06-18 12:00:00'),
            DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', '2026-06-18 13:00:00'),
            3600,
        );
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) NOT NULL PRIMARY KEY, value CLOB NOT NULL, value_type VARCHAR(32) NOT NULL, sensitive BOOLEAN NOT NULL DEFAULT 0, modified_at DATETIME DEFAULT NULL, modified_by VARCHAR(180) DEFAULT NULL)');
        $connection->executeStatement('CREATE TABLE user_account (uid VARCHAR(36) PRIMARY KEY NOT NULL, role VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL)');

        return $connection;
    }
}

final class RecordingAutoBanAlertDispatcher implements UiAlertDispatcherInterface
{
    /**
     * @var list<array{user: string, alert: UiAlert|Message|UiAlertTranslation, delivery: UiAlertDelivery|UiAlertDeliveryOptions, presentation: UiAlertPresentation|null}>
     */
    public array $userAlerts = [];

    public function addAlert(
        UiAlert|Message|UiAlertTranslation $alert,
        UiAlertDelivery|UiAlertDeliveryOptions $delivery = UiAlertDelivery::Direct,
        ?UiAlertPresentation $presentation = null,
    ): bool {
        return true;
    }

    public function addAlertToTopic(
        string $topic,
        UiAlert|Message|UiAlertTranslation $alert,
        UiAlertDelivery|UiAlertDeliveryOptions $delivery = UiAlertDelivery::Queue,
        ?UiAlertPresentation $presentation = null,
    ): bool {
        return true;
    }

    public function addAlertToUser(
        UserAccount|UserInterface|string $user,
        UiAlert|Message|UiAlertTranslation $alert,
        UiAlertDelivery|UiAlertDeliveryOptions $delivery = UiAlertDelivery::Queue,
        ?UiAlertPresentation $presentation = null,
    ): bool {
        $this->userAlerts[] = [
            'user' => is_string($user) ? $user : $user->getUserIdentifier(),
            'alert' => $alert,
            'delivery' => $delivery,
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
        return true;
    }
}
