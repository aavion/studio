<?php

declare(strict_types=1);

namespace App\Entity;

use App\Core\Message\MessageCode;
use App\Core\Message\MessageException;
use App\Core\Message\MessageKey;
use App\Core\Validation\Uid;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'content_revision')]
#[ORM\UniqueConstraint(name: 'uniq_content_revision_version', columns: ['content_uid', 'version'])]
#[ORM\Index(name: 'idx_content_revision_content', columns: ['content_uid'])]
#[ORM\Index(name: 'idx_content_revision_schema', columns: ['schema_uid'])]
#[ORM\Index(name: 'idx_content_revision_schema_version', columns: ['schema_version_uid'])]
class ContentRevision
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $uid;

    #[ORM\ManyToOne(targetEntity: ContentItem::class, inversedBy: 'revisions')]
    #[ORM\JoinColumn(name: 'content_uid', referencedColumnName: 'uid', nullable: false, onDelete: 'CASCADE')]
    private ContentItem $content;

    #[ORM\Column]
    private int $version;

    #[ORM\ManyToOne(targetEntity: ContentSchema::class)]
    #[ORM\JoinColumn(name: 'schema_uid', referencedColumnName: 'uid', nullable: false, onDelete: 'RESTRICT')]
    private ContentSchema $schema;

    #[ORM\ManyToOne(targetEntity: ContentSchemaVersion::class)]
    #[ORM\JoinColumn(name: 'schema_version_uid', referencedColumnName: 'uid', nullable: false, onDelete: 'RESTRICT')]
    private ContentSchemaVersion $schemaVersion;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $changeSummary = null;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $metadata = [];

    /**
     * @var Collection<int, ContentFieldValue>
     */
    #[ORM\OneToMany(mappedBy: 'revision', targetEntity: ContentFieldValue::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $fieldValues;

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        string $uid,
        ContentItem $content,
        int $version,
        ContentSchemaVersion $schemaVersion,
        ?string $changeSummary = null,
        array $metadata = [],
    ) {
        $this->uid = Uid::assert($uid, 'Content revision UID');
        $this->content = $content;
        $this->version = self::assertVersion($version);
        $this->schema = $schemaVersion->schema();
        $this->schemaVersion = $schemaVersion;
        $this->changeSummary = $changeSummary;
        $this->metadata = $metadata;
        $this->fieldValues = new ArrayCollection();
    }

    public function uid(): string
    {
        return $this->uid;
    }

    public function content(): ContentItem
    {
        return $this->content;
    }

    public function attachTo(ContentItem $content): void
    {
        $this->content = $content;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function schema(): ContentSchema
    {
        return $this->schema;
    }

    public function schemaVersion(): ContentSchemaVersion
    {
        return $this->schemaVersion;
    }

    public function addFieldValue(ContentFieldValue $fieldValue): void
    {
        if (!$this->fieldValues->contains($fieldValue)) {
            $this->fieldValues->add($fieldValue);
            $fieldValue->attachTo($this);
        }
    }

    /**
     * @return Collection<int, ContentFieldValue>
     */
    public function fieldValues(): Collection
    {
        return $this->fieldValues;
    }

    private static function assertVersion(int $version): int
    {
        if ($version < 1) {
            throw MessageException::forMessage(MessageCode::E_INVALID_ARGUMENT, MessageKey::CONTENT_FIELD_VALUE_VERSION_INVALID, [
                '%version%' => $version,
            ]);
        }

        return $version;
    }
}
