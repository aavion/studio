<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\State\StateMarkerKey;
use App\Core\State\StateSubjectType;
use App\Entity\ApiKey;
use App\Entity\UserAccount;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DeletedUserCleanup
{
    public const DELETED_USER_UID = '00000000-0000-0000-0000-000000000099';
    public const DELETED_USER_USERNAME = 'deleted-user';
    private const DELETED_USER_EMAIL = 'deleted-user@localhost.local';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserFlowConfig $userFlowConfig,
    ) {
    }

    public function retentionDays(): int
    {
        return $this->userFlowConfig->deletedUserRetentionDays();
    }

    public function cutoff(?DateTimeImmutable $now = null): DateTimeImmutable
    {
        return ($now ?? new DateTimeImmutable())->modify(sprintf('-%d days', $this->retentionDays()));
    }

    /**
     * @return list<array{user: UserAccount, deleted_at: DateTimeImmutable|null, deleted_by: string|null, cleanup_eligible: bool}>
     */
    public function deletedUsers(?DateTimeImmutable $now = null): array
    {
        $users = array_values(array_filter(
            $this->entityManager->getRepository(UserAccount::class)->findBy(['status' => UserAccountStatus::Deleted], ['username' => 'ASC']),
            static fn (mixed $user): bool => $user instanceof UserAccount && self::DELETED_USER_UID !== $user->uid(),
        ));
        $markers = $this->deletionMarkers(array_map(static fn (UserAccount $user): string => $user->uid(), $users));
        $cutoff = $this->cutoff($now);

        return array_map(static function (UserAccount $user) use ($markers, $cutoff): array {
            $marker = $markers[$user->uid()] ?? null;
            $deletedAt = $marker['deleted_at'] ?? null;

            return [
                'user' => $user,
                'deleted_at' => $deletedAt,
                'deleted_by' => $marker['deleted_by'] ?? null,
                'cleanup_eligible' => $deletedAt instanceof DateTimeImmutable && $deletedAt <= $cutoff,
            ];
        }, $users);
    }

    /**
     * @return array{removed: int, retention_days: int, cutoff: DateTimeImmutable, user_uids: list<string>}
     */
    public function cleanupExpired(?DateTimeImmutable $now = null): array
    {
        $cutoff = $this->cutoff($now);
        $rows = array_values(array_filter(
            $this->deletedUsers($now),
            static fn (array $row): bool => true === $row['cleanup_eligible'],
        ));
        $uids = array_map(static fn (array $row): string => $row['user']->uid(), $rows);

        if ([] === $rows) {
            return [
                'removed' => 0,
                'retention_days' => $this->retentionDays(),
                'cutoff' => $cutoff,
                'user_uids' => [],
            ];
        }

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $deletedUser = $this->deletedUserAccount();

            foreach ($rows as $row) {
                foreach ($this->entityManager->getRepository(ApiKey::class)->findBy(['user' => $row['user']]) as $apiKey) {
                    if ($apiKey instanceof ApiKey) {
                        $apiKey->reassignToUser($deletedUser);
                    }
                }
            }

            $this->entityManager->flush();

            foreach ($rows as $row) {
                $this->entityManager->remove($row['user']);
            }

            $this->entityManager->flush();

            foreach ($uids as $uid) {
                $connection->delete('state_marker', [
                    'subject_type' => StateSubjectType::USER_ACCOUNT,
                    'subject_uid' => $uid,
                ]);
            }

            $connection->commit();
        } catch (\Throwable $throwable) {
            $connection->rollBack();

            throw $throwable;
        }

        return [
            'removed' => count($uids),
            'retention_days' => $this->retentionDays(),
            'cutoff' => $cutoff,
            'user_uids' => $uids,
        ];
    }

    /**
     * @param list<string> $userUids
     *
     * @return array<string, array{deleted_at: DateTimeImmutable|null, deleted_by: string|null}>
     */
    private function deletionMarkers(array $userUids): array
    {
        if ([] === $userUids) {
            return [];
        }

        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT subject_uid, marker_at, marker_by FROM state_marker WHERE subject_type = ? AND marker_key = ? AND marker_value = ? AND subject_uid IN (?) AND subject_uid <> ?',
            [StateSubjectType::USER_ACCOUNT, StateMarkerKey::STATUS_CHANGED, UserAccountStatus::Deleted->value, $userUids, self::DELETED_USER_UID],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ArrayParameterType::STRING, ParameterType::STRING],
        );
        $markers = [];

        foreach ($rows as $row) {
            $uid = (string) $row['subject_uid'];
            $markerAt = is_string($row['marker_at'] ?? null) ? new DateTimeImmutable($row['marker_at']) : null;
            $markers[$uid] = [
                'deleted_at' => $markerAt,
                'deleted_by' => is_string($row['marker_by'] ?? null) ? $row['marker_by'] : null,
            ];
        }

        return $markers;
    }

    private function deletedUserAccount(): UserAccount
    {
        $user = $this->entityManager->find(UserAccount::class, self::DELETED_USER_UID);

        if ($user instanceof UserAccount) {
            return $user;
        }

        $user = new UserAccount(
            self::DELETED_USER_UID,
            self::DELETED_USER_USERNAME,
            self::DELETED_USER_EMAIL,
            'disabled',
            status: UserAccountStatus::Deleted,
        );
        $this->entityManager->persist($user);

        return $user;
    }
}
