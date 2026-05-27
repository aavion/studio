<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\ActionLog\ActionLog;
use App\Core\ActionLog\ActionLogEntry;
use App\Core\ActionLog\ActionLogStatus;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageLevel;
use App\Core\Message\MessageKey;
use App\Core\Message\WorkflowResultMessageReporterInterface;
use App\Core\State\StateMarkerKey;
use App\Core\State\StateSubjectType;
use App\Core\Workflow\WorkflowResult;
use Doctrine\DBAL\Connection;

final readonly class SetupPasswordResetRunner
{
    public function __construct(
        private WorkflowResultMessageReporterInterface $messageReporter,
        private SetupDatabaseConnectionFactory $connectionFactory = new SetupDatabaseConnectionFactory(),
    )
    {
    }

    public function findUser(string $projectDir, string $databaseUrl, string $username): ?SetupPasswordResetUser
    {
        $connection = $this->connectionFactory->create($projectDir, $databaseUrl);
        $row = $connection->fetchAssociative(
            'SELECT uid, username, email, status FROM user_account WHERE username = ?',
            [$username],
        );

        if (!is_array($row)) {
            return null;
        }

        return new SetupPasswordResetUser(
            (string) $row['uid'],
            (string) $row['username'],
            (string) $row['email'],
            (string) $row['status'],
        );
    }

    /**
     * @return WorkflowResult<ActionLog>
     */
    public function reset(string $projectDir, string $databaseUrl, string $username, string $newPassword, string $actor = 'setup_cli'): WorkflowResult
    {
        $entry = ActionLogEntry::pending('reset_user_password')->start();
        $log = ActionLog::create();
        $user = $this->findUser($projectDir, $databaseUrl, $username);

        if (!$user instanceof SetupPasswordResetUser) {
            $issue = Message::create(
                MessageCode::E_INVALID_ARGUMENT,
                MessageKey::SETUP_PASSWORD_RESET_USER_NOT_FOUND,
                ['%username%' => $username],
                ['username' => $username],
                MessageLevel::Warning,
            );

            return $this->report(WorkflowResult::invalid([$issue], [
                'halt_on_error' => true,
                'action_log' => $log->add($entry->finish(ActionLogStatus::Failed, [$issue]))->toArray(),
            ]), $username, $actor);
        }

        $now = gmdate('Y-m-d H:i:s');
        $connection = $this->connectionFactory->create($projectDir, $databaseUrl);
        $connection->update('user_account', [
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        ], ['uid' => $user->uid()]);
        $this->upsertStateMarker($connection, $user->uid(), StateMarkerKey::PASSWORD_CHANGED, $now, $actor);
        $this->upsertStateMarker($connection, $user->uid(), StateMarkerKey::MODIFIED, $now, $actor, 'password');

        $message = Message::success(MessageKey::SETUP_PASSWORD_RESET_COMPLETED, ['%username%' => $user->username()]);
        $log = $log->add($entry->finish(ActionLogStatus::Success, context: $user->toArray(), messages: [$message]));

        return $this->report(WorkflowResult::success($log, [
            'halt_on_error' => false,
            'username' => $user->username(),
            'uid' => $user->uid(),
        ]), $username, $actor);
    }

    private function report(WorkflowResult $result, string $username, string $actor): WorkflowResult
    {
        return $this->messageReporter->report($result, [
            'operation' => 'setup.password_reset',
            'username' => $username,
            'actor' => $actor,
        ]);
    }

    private function upsertStateMarker(Connection $connection, string $userUid, string $markerKey, string $markerAt, string $markerBy, ?string $markerValue = null): void
    {
        $values = [
            'marker_at' => $markerAt,
            'marker_by' => $markerBy,
            'marker_value' => $markerValue,
            'metadata' => '{}',
        ];
        $where = [
            'subject_type' => StateSubjectType::USER_ACCOUNT,
            'subject_uid' => $userUid,
            'marker_key' => $markerKey,
        ];

        $connection->fetchOne(
            'SELECT uid FROM state_marker WHERE subject_type = ? AND subject_uid = ? AND marker_key = ?',
            [StateSubjectType::USER_ACCOUNT, $userUid, $markerKey],
        )
            ? $connection->update('state_marker', $values, $where)
            : $connection->insert('state_marker', ['uid' => $this->uuid(), ...$where, ...$values]);
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
