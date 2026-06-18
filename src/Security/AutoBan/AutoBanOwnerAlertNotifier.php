<?php

declare(strict_types=1);

namespace App\Security\AutoBan;

use App\Core\Id\UuidFactory;
use App\Core\Message\Message;
use App\Core\Message\MessageReporterInterface;
use App\Security\UserAccountStatus;
use App\Security\UserRole;
use App\Security\SecurityMessageCode;
use App\Security\SecurityMessageKey;
use App\View\Alert\UiAlertAction;
use App\View\Alert\UiAlertDelivery;
use App\View\Alert\UiAlertDispatcherInterface;
use App\View\Alert\UiAlertPresentation;
use App\View\Alert\UiAlertTranslation;
use Doctrine\DBAL\Connection;
use Throwable;

final readonly class AutoBanOwnerAlertNotifier
{
    public function __construct(
        private AutoBanPolicy $policy,
        private Connection $connection,
        private UiAlertDispatcherInterface $alerts,
        private ?MessageReporterInterface $messageReporter = null,
        private UuidFactory $uuidFactory = new UuidFactory(),
    ) {
    }

    public function notifyBanTriggered(ActiveAutoBan $ban): void
    {
        if (!$this->policy->newBanOwnerAlertsEnabled()) {
            return;
        }

        try {
            $alert = UiAlertTranslation::warning('admin.auto_bans.alerts.triggered', [
                '%subject%' => $ban->subjectLabel(),
            ]);
            $presentation = UiAlertPresentation::hidden(actions: [
                UiAlertAction::link('Review', '/admin/security/auto-bans'),
            ], id: 'auto-ban-triggered-'.$ban->key().'-'.$this->uuidFactory->generate());

            foreach ($this->ownerUids() as $uid) {
                $this->alerts->addAlertToUser($uid, $alert, UiAlertDelivery::Queue, $presentation);
            }
        } catch (Throwable $error) {
            $this->reportDeliveryFailure($error, $ban);

            return;
        }
    }

    /**
     * @return list<string>
     */
    private function ownerUids(): array
    {
        $uids = $this->connection->fetchFirstColumn(
            'SELECT uid FROM user_account WHERE role = ? AND status = ?',
            [UserRole::Owner->value, UserAccountStatus::Active->value],
        );

        return array_values(array_filter(
            array_map(static fn (mixed $uid): string => is_string($uid) ? trim($uid) : '', $uids),
            static fn (string $uid): bool => '' !== $uid,
        ));
    }

    private function reportDeliveryFailure(Throwable $error, ActiveAutoBan $ban): void
    {
        try {
            $this->messageReporter?->report(Message::exception(
                SecurityMessageCode::AUTO_BAN_ALERT_DELIVERY_DEGRADED,
                SecurityMessageKey::AUTO_BAN_ALERT_DELIVERY_DEGRADED,
                context: [
                    'operation' => 'notify_ban_triggered',
                    'exception' => $error::class,
                    'message' => $error->getMessage(),
                    'active_ban_key' => $ban->key(),
                    'subject_type' => $ban->subjectType(),
                ],
            ), ['component' => self::class]);
        } catch (Throwable) {
        }
    }
}
