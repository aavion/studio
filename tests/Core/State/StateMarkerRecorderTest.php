<?php

declare(strict_types=1);

namespace App\Tests\Core\State;

use App\Core\Message\MessageException;
use App\Core\State\StateMarkerRecorder;
use App\Entity\UserAccount;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class StateMarkerRecorderTest extends KernelTestCase
{
    public function testItStoresSafeFallbackMetadataWhenEncodingFails(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $entityManager->getConnection();
        $recorder = self::getContainer()->get(StateMarkerRecorder::class);
        $subjectType = 'test_state_marker';
        $subjectUid = '65000000-0000-0000-0000-000000000001';

        $connection->delete('state_marker', [
            'subject_type' => $subjectType,
            'subject_uid' => $subjectUid,
        ]);

        try {
            $recorder->record($subjectType, $subjectUid, 'metadata_encoded', metadata: [
                'invalid_utf8' => "\xB1\x31",
            ]);
            $entityManager->flush();

            $metadata = $connection->fetchOne(
                'SELECT metadata FROM state_marker WHERE subject_type = ? AND subject_uid = ? AND marker_key = ?',
                [$subjectType, $subjectUid, 'metadata_encoded'],
            );

            self::assertSame('{"encoding_error":true,"metadata":[]}', $metadata);
            self::assertSame(['encoding_error' => true, 'metadata' => []], $recorder->history($subjectType, $subjectUid)[0]['metadata']);
        } finally {
            $connection->delete('state_marker', [
                'subject_type' => $subjectType,
                'subject_uid' => $subjectUid,
            ]);
        }
    }

    public function testItDoesNotPersistMarkersWhenTheOwningFlushFails(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $entityManager->getConnection();
        $recorder = self::getContainer()->get(StateMarkerRecorder::class);
        $subjectType = 'user_account';
        $subjectUid = '65000000-0000-0000-0000-000000000002';
        $otherUid = '65000000-0000-0000-0000-000000000003';

        foreach ([$subjectUid, $otherUid] as $uid) {
            $connection->delete('state_marker', ['subject_type' => $subjectType, 'subject_uid' => $uid]);
            $connection->delete('user_account', ['uid' => $uid]);
        }

        $user = new UserAccount($subjectUid, 'markeratomic', 'markeratomic@example.test', 'hash');
        $other = new UserAccount($otherUid, 'markerother', 'markerother@example.test', 'hash');
        $entityManager->persist($user);
        $entityManager->persist($other);
        $entityManager->flush();

        try {
            $recorder->record($subjectType, $subjectUid, 'modified', 'test', 'profile');
            $other->changeEmail($user->email());

            try {
                $entityManager->flush();
                self::fail('Expected duplicate email validation to reject the flush.');
            } catch (MessageException) {
                self::assertFalse($connection->fetchOne(
                    'SELECT uid FROM state_marker WHERE subject_type = ? AND subject_uid = ? AND marker_key = ?',
                    [$subjectType, $subjectUid, 'modified'],
                ));
            }
        } finally {
            $connection->delete('state_marker', ['subject_type' => $subjectType, 'subject_uid' => $subjectUid]);
            $connection->delete('user_account', ['uid' => $subjectUid]);
            $connection->delete('user_account', ['uid' => $otherUid]);
        }
    }

    public function testItCoalescesRepeatedMarkersBeforeFlush(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $entityManager->getConnection();
        $recorder = self::getContainer()->get(StateMarkerRecorder::class);
        $subjectType = 'test_state_marker';
        $subjectUid = '65000000-0000-0000-0000-000000000004';

        $connection->delete('state_marker', [
            'subject_type' => $subjectType,
            'subject_uid' => $subjectUid,
        ]);

        try {
            $recorder->record($subjectType, $subjectUid, 'modified', markerValue: 'first');
            $recorder->record($subjectType, $subjectUid, 'modified', markerValue: 'second');
            $entityManager->flush();

            self::assertSame(1, (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM state_marker WHERE subject_type = ? AND subject_uid = ? AND marker_key = ?',
                [$subjectType, $subjectUid, 'modified'],
            ));
            self::assertSame('second', $connection->fetchOne(
                'SELECT marker_value FROM state_marker WHERE subject_type = ? AND subject_uid = ? AND marker_key = ?',
                [$subjectType, $subjectUid, 'modified'],
            ));
        } finally {
            $connection->delete('state_marker', [
                'subject_type' => $subjectType,
                'subject_uid' => $subjectUid,
            ]);
        }
    }
}
