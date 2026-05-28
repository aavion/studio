<?php

declare(strict_types=1);

namespace App\Core\State;

use Doctrine\ORM\EntityManagerInterface;

final readonly class StateMarkerRecorder
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function record(
        string $subjectType,
        string $subjectUid,
        string $markerKey,
        ?string $markerBy = null,
        ?string $markerValue = null,
        array $metadata = [],
    ): void {
        $connection = $this->entityManager->getConnection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $where = [
            'subject_type' => $subjectType,
            'subject_uid' => $subjectUid,
            'marker_key' => $markerKey,
        ];
        $values = [
            'marker_at' => $now,
            'marker_by' => $markerBy,
            'marker_value' => $markerValue,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
        ];
        $existingUid = $connection->fetchOne(
            'SELECT uid FROM state_marker WHERE subject_type = ? AND subject_uid = ? AND marker_key = ?',
            [$subjectType, $subjectUid, $markerKey],
        );

        if (is_string($existingUid) && '' !== $existingUid) {
            $connection->update('state_marker', $values, ['uid' => $existingUid]);

            return;
        }

        $connection->insert('state_marker', ['uid' => self::uuid(), ...$where, ...$values]);
    }

    /**
     * @return list<array{marker_key: string, marker_at: string, marker_by: string|null, marker_value: string|null, metadata: array<string, mixed>}>
     */
    public function history(string $subjectType, string $subjectUid): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT marker_key, marker_at, marker_by, marker_value, metadata FROM state_marker WHERE subject_type = ? AND subject_uid = ? ORDER BY marker_at DESC, marker_key ASC',
            [$subjectType, $subjectUid],
        );
        $history = [];

        foreach ($rows as $row) {
            $metadata = json_decode((string) ($row['metadata'] ?? '{}'), true);

            $history[] = [
                'marker_key' => (string) $row['marker_key'],
                'marker_at' => (string) $row['marker_at'],
                'marker_by' => is_string($row['marker_by'] ?? null) ? $row['marker_by'] : null,
                'marker_value' => is_string($row['marker_value'] ?? null) ? $row['marker_value'] : null,
                'metadata' => is_array($metadata) ? $metadata : [],
            ];
        }

        return $history;
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}
