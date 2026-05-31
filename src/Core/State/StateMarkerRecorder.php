<?php

declare(strict_types=1);

namespace App\Core\State;

use App\Entity\StateMarker;
use Doctrine\ORM\EntityManagerInterface;

final class StateMarkerRecorder
{
    /**
     * @var array<string, StateMarker>
     */
    private array $pendingMarkers = [];

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
        $now = new \DateTimeImmutable();
        $metadata = $this->safeMetadata($metadata);
        $lookup = [
            'subjectType' => $subjectType,
            'subjectUid' => $subjectUid,
            'markerKey' => $markerKey,
        ];
        $pendingKey = implode("\0", [$subjectType, $subjectUid, $markerKey]);
        $marker = $this->pendingMarkers[$pendingKey] ?? null;

        if (!$marker instanceof StateMarker || !$this->entityManager->contains($marker)) {
            unset($this->pendingMarkers[$pendingKey]);
            $marker = $this->entityManager->getRepository(StateMarker::class)->findOneBy($lookup);
        }

        if ($marker instanceof StateMarker) {
            $marker->update($now, $markerBy, $markerValue, $metadata);
            $this->pendingMarkers[$pendingKey] = $marker;

            return;
        }

        $marker = new StateMarker(
            self::uuid(),
            $subjectType,
            $subjectUid,
            $markerKey,
            $now,
            $markerBy,
            $markerValue,
            $metadata,
        );
        $this->entityManager->persist($marker);
        $this->pendingMarkers[$pendingKey] = $marker;
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

    /**
     * @param array<string, mixed> $metadata
     */
    private function safeMetadata(array $metadata): array
    {
        try {
            $encoded = json_encode($metadata, JSON_THROW_ON_ERROR);
            $decoded = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return ['encoding_error' => true, 'metadata' => []];
        }
    }
}
