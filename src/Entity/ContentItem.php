<?php

declare(strict_types=1);

namespace App\Entity;

use App\Content\ContentStatus;
use App\Content\ContentVisibility;
use App\Content\Routing\ContentSlug;
use App\Content\Schema\ContentSchemaField;
use App\Core\Access\AccessLevel;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageException;
use App\Core\Message\MessageKey;
use App\Core\Validation\Identifier;
use App\Repository\ContentItemRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContentItemRepository::class)]
#[ORM\Table(name: 'content_item')]
#[ORM\Index(name: 'idx_content_item_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_content_item_parent', columns: ['parent_uid'])]
#[ORM\Index(name: 'idx_content_item_parent_sort', columns: ['parent_uid', 'sort_order'])]
#[ORM\Index(name: 'idx_content_item_status_visibility', columns: ['status', 'visibility'])]
#[ORM\Index(name: 'idx_content_item_schema', columns: ['schema_uid'])]
#[ORM\Index(name: 'idx_content_item_schema_version', columns: ['schema_uid', 'schema_version'])]
#[ORM\Index(name: 'idx_content_item_schema_status_visibility', columns: ['schema_uid', 'status', 'visibility'])]
#[ORM\Index(name: 'idx_content_item_active_revision', columns: ['active_revision_uid'])]
#[ORM\UniqueConstraint(name: 'uniq_content_item_parent_slug', columns: ['parent_uid', 'slug'])]
#[ORM\UniqueConstraint(name: 'uniq_content_item_custom_url', columns: ['custom_url'])]
class ContentItem
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $uid;

    #[ORM\Column(length: 160)]
    private string $slug;

    #[ORM\Column(enumType: ContentStatus::class)]
    private ContentStatus $status = ContentStatus::Draft;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $parentUid = null;

    #[ORM\Column]
    private int $sortOrder = 0;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $customUrl = null;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $redirectTarget = null;

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
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $availableLanguages = ['en'];

    /**
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $availableVariants = ['default'];

    #[ORM\Column(enumType: ContentVisibility::class)]
    private ContentVisibility $visibility = ContentVisibility::Public;

    /**
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $aclRestrictions = [];

    #[ORM\Column(nullable: true)]
    private ?int $viewMinLevel = null;

    /**
     * @var list<string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $viewGroupIdentifiers = null;

    #[ORM\Column(nullable: true)]
    private ?int $editMinLevel = null;

    /**
     * @var list<string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $editGroupIdentifiers = null;

    #[ORM\Column(nullable: true)]
    private ?int $manageMinLevel = null;

    /**
     * @var list<string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $manageGroupIdentifiers = null;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $createdBy = null;

    #[ORM\Column]
    private DateTimeImmutable $modifiedAt;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $modifiedBy = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $publishedAt = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $publishedBy = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $archivedAt = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $archivedBy = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $deletedAt = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $deletedBy = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $lockedAt = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $lockedBy = null;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $metadata = [];

    /**
     * @var Collection<int, ContentRevision>
     */
    #[ORM\OneToMany(mappedBy: 'content', targetEntity: ContentRevision::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $revisions;

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        string $uid,
        string $slug,
        array $metadata = [],
        ?string $createdBy = null,
        ?DateTimeImmutable $createdAt = null,
    ) {
        $this->uid = self::assertUid($uid, 'Content UID');
        $this->slug = ContentSlug::fromString($slug)->value();
        $this->metadata = self::assertMetadata($metadata);
        $this->createdBy = $createdBy;
        $this->modifiedBy = $createdBy;
        $this->createdAt = $createdAt ?? new DateTimeImmutable();
        $this->modifiedAt = $this->createdAt;
        $this->revisions = new ArrayCollection();
    }

    public function uid(): string
    {
        return $this->uid;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function rename(string $slug, ?string $modifiedBy = null, ?DateTimeImmutable $modifiedAt = null): void
    {
        $this->slug = ContentSlug::fromString($slug)->value();
        $this->touch($modifiedBy, $modifiedAt);
    }

    public function status(): ContentStatus
    {
        return $this->status;
    }

    public function publish(?string $publishedBy = null, ?DateTimeImmutable $publishedAt = null): void
    {
        $this->status = ContentStatus::Published;
        $this->publishedBy = $publishedBy;
        $this->publishedAt = $publishedAt ?? new DateTimeImmutable();
        $this->touch($publishedBy, $this->publishedAt);
    }

    public function archive(?string $archivedBy = null, ?DateTimeImmutable $archivedAt = null): void
    {
        $this->status = ContentStatus::Archived;
        $this->archivedBy = $archivedBy;
        $this->archivedAt = $archivedAt ?? new DateTimeImmutable();
        $this->touch($archivedBy, $this->archivedAt);
    }

    public function parentUid(): ?string
    {
        return $this->parentUid;
    }

    public function moveTo(?string $parentUid, int $sortOrder = 0, ?string $modifiedBy = null): void
    {
        $this->parentUid = null === $parentUid ? null : self::assertUid($parentUid, 'Parent content UID');
        $this->sortOrder = $sortOrder;
        $this->touch($modifiedBy);
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    public function customUrl(): ?string
    {
        return $this->customUrl;
    }

    public function setCustomUrl(?string $customUrl, ?string $modifiedBy = null): void
    {
        $this->customUrl = $customUrl;
        $this->touch($modifiedBy);
    }

    public function redirectTarget(): ?string
    {
        return $this->redirectTarget;
    }

    public function setRedirectTarget(?string $redirectTarget, ?string $modifiedBy = null): void
    {
        $this->redirectTarget = $redirectTarget;
        $this->touch($modifiedBy);
    }

    public function schemaUid(): ?string
    {
        return $this->schema?->uid();
    }

    public function schema(): ?ContentSchema
    {
        return $this->schema;
    }

    public function setSchema(?ContentSchema $schema, ?string $modifiedBy = null): void
    {
        $this->schema = $schema;
        $this->touch($modifiedBy);
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

    public function activateRevision(ContentRevision $revision, ?string $modifiedBy = null): void
    {
        if ($revision->content() !== $this) {
            $revision->attachTo($this);
        }

        $this->addRevision($revision);
        $this->activeRevision = $revision;
        $this->schema = $revision->schema();
        $this->schemaVersion = $revision->schemaVersion()->version();
        $this->version = $revision->version();
        $this->touch($modifiedBy);
    }

    public function disableActiveRevision(?string $modifiedBy = null): void
    {
        $this->activeRevision = null;
        $this->touch($modifiedBy);
    }

    public function version(): int
    {
        return $this->version;
    }

    public function bumpVersion(?string $modifiedBy = null): void
    {
        ++$this->version;
        $this->touch($modifiedBy);
    }

    /**
     * @return list<string>
     */
    public function availableLanguages(): array
    {
        return $this->availableLanguages;
    }

    /**
     * @param list<string> $languages
     */
    public function setAvailableLanguages(array $languages, ?string $modifiedBy = null): void
    {
        $this->availableLanguages = self::assertNonEmptyStringList($languages, 'Available languages');
        $this->touch($modifiedBy);
    }

    /**
     * @return list<string>
     */
    public function availableVariants(): array
    {
        return $this->availableVariants;
    }

    /**
     * @param list<string> $variants
     */
    public function setAvailableVariants(array $variants, ?string $modifiedBy = null): void
    {
        $this->availableVariants = self::assertNonEmptyStringList($variants, 'Available variants');
        $this->touch($modifiedBy);
    }

    public function visibility(): ContentVisibility
    {
        return $this->visibility;
    }

    public function setVisibility(ContentVisibility $visibility, ?string $modifiedBy = null): void
    {
        $this->visibility = $visibility;
        $this->touch($modifiedBy);
    }

    /**
     * @return list<string>
     */
    public function aclRestrictions(): array
    {
        return $this->aclRestrictions;
    }

    /**
     * @param list<string> $aclRestrictions
     */
    public function setAclRestrictions(array $aclRestrictions, ?string $modifiedBy = null): void
    {
        $this->aclRestrictions = self::assertStringList($aclRestrictions, 'ACL restrictions');
        $this->touch($modifiedBy);
    }

    /**
     * @param list<string>|null $groupIdentifiers
     */
    public function setViewRule(?int $minLevel, ?array $groupIdentifiers = null, ?string $modifiedBy = null): void
    {
        $this->viewMinLevel = AccessLevel::assert($minLevel);
        $this->viewGroupIdentifiers = self::assertOptionalGroupIdentifierList($groupIdentifiers);
        $this->touch($modifiedBy);
    }

    public function viewMinLevel(): ?int
    {
        return $this->viewMinLevel;
    }

    /**
     * @return list<string>|null
     */
    public function viewGroupIdentifiers(): ?array
    {
        return $this->viewGroupIdentifiers;
    }

    /**
     * @param list<string>|null $groupIdentifiers
     */
    public function setEditRule(?int $minLevel, ?array $groupIdentifiers = null, ?string $modifiedBy = null): void
    {
        $this->editMinLevel = AccessLevel::assert($minLevel);
        $this->editGroupIdentifiers = self::assertOptionalGroupIdentifierList($groupIdentifiers);
        $this->touch($modifiedBy);
    }

    public function editMinLevel(): ?int
    {
        return $this->editMinLevel;
    }

    /**
     * @return list<string>|null
     */
    public function editGroupIdentifiers(): ?array
    {
        return $this->editGroupIdentifiers;
    }

    /**
     * @param list<string>|null $groupIdentifiers
     */
    public function setManageRule(?int $minLevel, ?array $groupIdentifiers = null, ?string $modifiedBy = null): void
    {
        $this->manageMinLevel = AccessLevel::assert($minLevel);
        $this->manageGroupIdentifiers = self::assertOptionalGroupIdentifierList($groupIdentifiers);
        $this->touch($modifiedBy);
    }

    public function manageMinLevel(): ?int
    {
        return $this->manageMinLevel;
    }

    /**
     * @return list<string>|null
     */
    public function manageGroupIdentifiers(): ?array
    {
        return $this->manageGroupIdentifiers;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function createdBy(): ?string
    {
        return $this->createdBy;
    }

    public function modifiedAt(): DateTimeImmutable
    {
        return $this->modifiedAt;
    }

    public function modifiedBy(): ?string
    {
        return $this->modifiedBy;
    }

    public function publishedAt(): ?DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function publishedBy(): ?string
    {
        return $this->publishedBy;
    }

    public function archivedAt(): ?DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function archivedBy(): ?string
    {
        return $this->archivedBy;
    }

    public function deletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function deletedBy(): ?string
    {
        return $this->deletedBy;
    }

    public function markDeleted(?string $deletedBy = null, ?DateTimeImmutable $deletedAt = null): void
    {
        $this->deletedBy = $deletedBy;
        $this->deletedAt = $deletedAt ?? new DateTimeImmutable();
        $this->touch($deletedBy, $this->deletedAt);
    }

    public function lockedAt(): ?DateTimeImmutable
    {
        return $this->lockedAt;
    }

    public function lockedBy(): ?string
    {
        return $this->lockedBy;
    }

    public function lock(string $lockedBy, ?DateTimeImmutable $lockedAt = null): void
    {
        $this->lockedBy = $lockedBy;
        $this->lockedAt = $lockedAt ?? new DateTimeImmutable();
    }

    public function unlock(): void
    {
        $this->lockedBy = null;
        $this->lockedAt = null;
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
    public function replaceMetadata(array $metadata, ?string $modifiedBy = null): void
    {
        $this->metadata = self::assertMetadata($metadata);
        $this->touch($modifiedBy);
    }

    public function metadataValue(string $key): mixed
    {
        return $this->metadata[$key] ?? null;
    }

    public function setMetadataValue(string $key, mixed $value, ?string $modifiedBy = null): void
    {
        if ('' === trim($key)) {
            throw MessageException::forMessage(MessageCode::E_INVALID_ARGUMENT, MessageKey::CONTENT_METADATA_KEY_EMPTY);
        }

        self::assertMetadataKey($key);

        $this->metadata[$key] = $value;
        $this->touch($modifiedBy);
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

    private function touch(?string $modifiedBy = null, ?DateTimeImmutable $modifiedAt = null): void
    {
        $this->modifiedBy = $modifiedBy;
        $this->modifiedAt = $modifiedAt ?? new DateTimeImmutable();
    }

    private static function assertUid(string $uid, string $label): string
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uid)) {
            throw MessageException::forMessage(MessageCode::E_INVALID_ARGUMENT, MessageKey::CONTENT_UID_INVALID, [
                '%label%' => $label,
                '%uid%' => $uid,
            ]);
        }

        return $uid;
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>
     */
    private static function assertMetadata(array $metadata): array
    {
        foreach (array_keys($metadata) as $key) {
            if (!is_string($key) || '' === trim($key)) {
                throw MessageException::forMessage(MessageCode::E_INVALID_ARGUMENT, MessageKey::CONTENT_METADATA_KEY_EMPTY);
            }

            self::assertMetadataKey($key);
        }

        return $metadata;
    }

    private static function assertMetadataKey(string $key): void
    {
        if (ContentSchemaField::isRequiredBaseIdentifier($key)) {
            throw MessageException::forMessage(MessageCode::E_INVALID_ARGUMENT, MessageKey::CONTENT_METADATA_RESERVED_SCHEMA_FIELD, [
                '%field_identifier%' => $key,
            ]);
        }
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function assertNonEmptyStringList(array $values, string $label): array
    {
        if ([] === $values) {
            throw MessageException::forMessage(MessageCode::E_INVALID_ARGUMENT, MessageKey::CONTENT_STRING_LIST_EMPTY, [
                '%label%' => $label,
            ]);
        }

        return self::assertStringList($values, $label);
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function assertStringList(array $values, string $label): array
    {
        foreach ($values as $value) {
            if (!is_string($value) || '' === trim($value)) {
                throw MessageException::forMessage(MessageCode::E_INVALID_ARGUMENT, MessageKey::CONTENT_STRING_LIST_INVALID, [
                    '%label%' => $label,
                ]);
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @param list<string>|null $values
     *
     * @return list<string>|null
     */
    private static function assertOptionalStringList(?array $values, string $label): ?array
    {
        if (null === $values) {
            return null;
        }

        return self::assertStringList($values, $label);
    }

    /**
     * @param list<string>|null $values
     *
     * @return list<string>|null
     */
    private static function assertOptionalGroupIdentifierList(?array $values): ?array
    {
        if (null === $values) {
            return null;
        }

        $identifiers = self::assertStringList($values, 'ACL group identifiers');

        foreach ($identifiers as $identifier) {
            Identifier::assertSnakeCase($identifier, MessageKey::ACCESS_GROUP_IDENTIFIER_INVALID);
        }

        return $identifiers;
    }
}
