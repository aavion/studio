<?php

declare(strict_types=1);

namespace App\Entity;

use App\Content\Schema\ContentSchemaSource;
use App\Core\Message\MessageKey;
use App\Core\Validation\Identifier;
use App\Core\Validation\Uid;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'content_schema')]
#[ORM\UniqueConstraint(name: 'uniq_content_schema_identifier', columns: ['identifier'])]
#[ORM\Index(name: 'idx_content_schema_source', columns: ['source'])]
#[ORM\Index(name: 'idx_content_schema_active_version', columns: ['active_version_uid'])]
class ContentSchema
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $uid;

    #[ORM\Column(length: 120)]
    private string $identifier;

    #[ORM\Column(enumType: ContentSchemaSource::class)]
    private ContentSchemaSource $source;

    #[ORM\Column]
    private bool $locked;

    #[ORM\ManyToOne(targetEntity: ContentSchemaVersion::class)]
    #[ORM\JoinColumn(name: 'active_version_uid', referencedColumnName: 'uid', nullable: true, onDelete: 'SET NULL')]
    private ?ContentSchemaVersion $activeVersion = null;

    /**
     * @var array<string, string>
     */
    #[ORM\Column(type: 'json')]
    private array $labels;

    /**
     * @var array<string, string>
     */
    #[ORM\Column(type: 'json')]
    private array $descriptions = [];

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $metadata = [];

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $createdBy = null;

    #[ORM\Column]
    private DateTimeImmutable $modifiedAt;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $modifiedBy = null;

    /**
     * @var Collection<int, ContentSchemaVersion>
     */
    #[ORM\OneToMany(mappedBy: 'schema', targetEntity: ContentSchemaVersion::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $versions;

    /**
     * @param array<string, string> $labels
     * @param array<string, string> $descriptions
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        string $uid,
        string $identifier,
        ContentSchemaSource $source,
        array $labels,
        bool $locked = false,
        array $descriptions = [],
        array $metadata = [],
        ?string $createdBy = null,
        ?DateTimeImmutable $createdAt = null,
    ) {
        $this->uid = Uid::assert($uid, 'Content schema UID');
        $this->identifier = Identifier::assertSnakeCase($identifier, MessageKey::CONTENT_SCHEMA_IDENTIFIER_INVALID, '%identifier%');
        $this->source = $source;
        $this->labels = $labels;
        $this->locked = $locked;
        $this->descriptions = $descriptions;
        $this->metadata = $metadata;
        $this->createdBy = $createdBy;
        $this->modifiedBy = $createdBy;
        $this->createdAt = $createdAt ?? new DateTimeImmutable();
        $this->modifiedAt = $this->createdAt;
        $this->versions = new ArrayCollection();
    }

    public function uid(): string
    {
        return $this->uid;
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function source(): ContentSchemaSource
    {
        return $this->source;
    }

    public function locked(): bool
    {
        return $this->locked;
    }

    public function activeVersionUid(): ?string
    {
        return $this->activeVersion?->uid();
    }

    public function activeVersion(): ?ContentSchemaVersion
    {
        return $this->activeVersion;
    }

    public function activateVersion(ContentSchemaVersion $version, ?string $modifiedBy = null): void
    {
        if ($version->schema() !== $this) {
            $version->attachTo($this);
        }

        $this->addVersion($version);
        $this->activeVersion = $version;
        $this->touch($modifiedBy);
    }

    public function disable(?string $modifiedBy = null): void
    {
        $this->activeVersion = null;
        $this->touch($modifiedBy);
    }

    public function addVersion(ContentSchemaVersion $version): void
    {
        if (!$this->versions->contains($version)) {
            $this->versions->add($version);
            $version->attachTo($this);
        }
    }

    /**
     * @return Collection<int, ContentSchemaVersion>
     */
    public function versions(): Collection
    {
        return $this->versions;
    }

    private function touch(?string $modifiedBy = null): void
    {
        $this->modifiedBy = $modifiedBy;
        $this->modifiedAt = new DateTimeImmutable();
    }
}
