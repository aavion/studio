<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\Id\UuidFactory;
use Doctrine\DBAL\Connection;

final readonly class SetupStateMarkerWriter
{
    public function __construct(private UuidFactory $uuidFactory = new UuidFactory())
    {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function upsert(
        Connection $connection,
        string $subjectType,
        string $subjectUid,
        string $markerKey,
        string $markerAt,
        ?string $markerBy = null,
        ?string $markerValue = null,
        array $metadata = [],
    ): void {
        $values = [
            'marker_at' => $markerAt,
            'marker_by' => $markerBy,
            'marker_value' => $markerValue,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
        ];
        $where = [
            'subject_type' => $subjectType,
            'subject_uid' => $subjectUid,
            'marker_key' => $markerKey,
        ];

        $connection->fetchOne(
            'SELECT uid FROM state_marker WHERE subject_type = ? AND subject_uid = ? AND marker_key = ?',
            [$subjectType, $subjectUid, $markerKey],
        )
            ? $connection->update('state_marker', $values, $where)
            : $connection->insert('state_marker', ['uid' => $this->uuidFactory->generate(), ...$where, ...$values]);
    }
}
