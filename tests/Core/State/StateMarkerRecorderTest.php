<?php

declare(strict_types=1);

namespace App\Tests\Core\State;

use App\Core\State\StateMarkerRecorder;
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

            $metadata = $connection->fetchOne(
                'SELECT metadata FROM state_marker WHERE subject_type = ? AND subject_uid = ? AND marker_key = ?',
                [$subjectType, $subjectUid, 'metadata_encoded'],
            );

            self::assertSame('{"encoding_error":true,"metadata":{}}', $metadata);
            self::assertSame(['encoding_error' => true, 'metadata' => []], $recorder->history($subjectType, $subjectUid)[0]['metadata']);
        } finally {
            $connection->delete('state_marker', [
                'subject_type' => $subjectType,
                'subject_uid' => $subjectUid,
            ]);
        }
    }
}
