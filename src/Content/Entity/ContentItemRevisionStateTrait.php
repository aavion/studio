<?php

declare(strict_types=1);

namespace App\Content\Entity;

use App\Entity\ContentRevision;
use App\Entity\ContentSchema;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

trait ContentItemRevisionStateTrait
{
    #[ORM\ManyToOne(targetEntity: ContentSchema::class)]
    #[ORM\JoinColumn(name: 'schema_uid', referencedColumnName: 'uid', nullable: true, onDelete: 'RESTRICT')]
    private ?ContentSchema $schema = null;

    #[ORM\Column(nullable: true)]
    private ?int $schemaVersion = null;

    #[ORM\ManyToOne(targetEntity: ContentRevision::class)]
    #[ORM\JoinColumn(name: 'active_revision_uid', referencedColumnName: 'uid', nullable: true, onDelete: 'SET NULL')]
    private ?ContentRevision $activeRevision = null;

    #[ORM\Column]
    private int $version = 1;

    /**
     * @var Collection<int, ContentRevision>
     */
    #[ORM\OneToMany(mappedBy: 'content', targetEntity: ContentRevision::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $revisions;

    public function schemaUid(): ?string
    {
        return $this->schema?->uid();
    }

    public function schema(): ?ContentSchema
    {
        return $this->schema;
    }

    public function setSchema(?ContentSchema $schema): void
    {
        $this->schema = $schema;
    }

    public function schemaVersion(): ?int
    {
        return $this->schemaVersion;
    }

    public function activeRevisionUid(): ?string
    {
        return $this->activeRevision?->uid();
    }

    public function activeRevision(): ?ContentRevision
    {
        return $this->activeRevision;
    }

    public function activateRevision(ContentRevision $revision): void
    {
        if ($revision->content() !== $this) {
            $revision->attachTo($this);
        }

        $this->addRevision($revision);
        $this->activeRevision = $revision;
        $this->schema = $revision->schema();
        $this->schemaVersion = $revision->schemaVersion()->version();
        $this->version = $revision->version();
    }

    public function disableActiveRevision(): void
    {
        $this->activeRevision = null;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function bumpVersion(): void
    {
        ++$this->version;
    }

    /**
     * @return Collection<int, ContentRevision>
     */
    public function revisions(): Collection
    {
        return $this->revisions;
    }

    public function addRevision(ContentRevision $revision): void
    {
        if (!$this->revisions->contains($revision)) {
            $this->revisions->add($revision);
            $revision->attachTo($this);
        }
    }

    private function initializeRevisionState(): void
    {
        $this->revisions = new ArrayCollection();
    }
}
