<?php

declare(strict_types=1);

namespace App\Entity;

use App\Core\Message\MessageException;
use App\Core\Message\MessageKey;
use App\Core\Validation\Identifier;
use App\Core\Validation\Uid;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'state_marker')]
#[ORM\UniqueConstraint(name: 'uniq_state_marker_subject_key', columns: ['subject_type', 'subject_uid', 'marker_key'])]
#[ORM\Index(name: 'idx_state_marker_subject', columns: ['subject_type', 'subject_uid'])]
#[ORM\Index(name: 'idx_state_marker_lookup', columns: ['subject_type', 'marker_key', 'marker_at'])]
#[ORM\Index(name: 'idx_state_marker_key_at', columns: ['marker_key', 'marker_at'])]
#[ORM\Index(name: 'idx_state_marker_by', columns: ['subject_type', 'marker_by'])]
class StateMarker
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $uid;

    #[ORM\Column(length: 80)]
    private string $subjectType;

    #[ORM\Column(length: 36)]
    private string $subjectUid;

    #[ORM\Column(length: 80)]
    private string $markerKey;

    #[ORM\Column]
    private DateTimeImmutable $markerAt;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $markerBy = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $markerValue = null;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $metadata = [];

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        string $uid,
        string $subjectType,
        string $subjectUid,
        string $markerKey,
        DateTimeImmutable $markerAt,
        ?string $markerBy = null,
        ?string $markerValue = null,
        array $metadata = [],
    ) {
        $this->uid = Uid::assert($uid, 'State marker UID');
        $this->subjectType = Identifier::assertSnakeCase($subjectType, MessageKey::STATE_SUBJECT_TYPE_INVALID, '%subject_type%');
        $this->subjectUid = Uid::assert($subjectUid, 'State marker subject UID');
        $this->markerKey = Identifier::assertSnakeCase($markerKey, MessageKey::STATE_MARKER_KEY_INVALID, '%marker_key%');
        $this->markerAt = $markerAt;
        $this->markerBy = $markerBy;
        $this->markerValue = $markerValue;
        $this->metadata = self::assertMetadata($metadata);
    }

    public function uid(): string
    {
        return $this->uid;
    }

    public function subjectType(): string
    {
        return $this->subjectType;
    }

    public function subjectUid(): string
    {
        return $this->subjectUid;
    }

    public function markerKey(): string
    {
        return $this->markerKey;
    }

    public function markerAt(): DateTimeImmutable
    {
        return $this->markerAt;
    }

    public function markerBy(): ?string
    {
        return $this->markerBy;
    }

    public function markerValue(): ?string
    {
        return $this->markerValue;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private static function assertMetadata(array $metadata): array
    {
        foreach (array_keys($metadata) as $key) {
            if (!is_string($key) || '' === trim($key)) {
                throw MessageException::invalidArgument(MessageKey::CONTENT_METADATA_KEY_EMPTY);
            }
        }

        return $metadata;
    }
}
