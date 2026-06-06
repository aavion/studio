<?php

declare(strict_types=1);

namespace App\Entity;

use App\Content\ContentStatus;
use App\Content\Entity\ContentItemAccessStateTrait;
use App\Content\Entity\ContentItemInput;
use App\Content\Entity\ContentItemLocalizationStateTrait;
use App\Content\Entity\ContentItemMetadataStateTrait;
use App\Content\Entity\ContentItemRevisionStateTrait;
use App\Content\Entity\ContentItemRoutingStateTrait;
use App\Content\Routing\ContentSlug;
use App\Repository\ContentItemRepository;
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
    use ContentItemAccessStateTrait;
    use ContentItemLocalizationStateTrait;
    use ContentItemMetadataStateTrait;
    use ContentItemRevisionStateTrait;
    use ContentItemRoutingStateTrait;

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $uid;

    #[ORM\Column(enumType: ContentStatus::class)]
    private ContentStatus $status = ContentStatus::Draft;

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        string $uid,
        string $slug,
        array $metadata = [],
    ) {
        $this->uid = ContentItemInput::uid($uid, 'Content UID');
        $this->slug = ContentSlug::fromString($slug)->value();
        $this->metadata = ContentItemInput::metadata($metadata);
        $this->initializeRevisionState();
    }

    public function uid(): string
    {
        return $this->uid;
    }

    public function status(): ContentStatus
    {
        return $this->status;
    }

    public function publish(): void
    {
        $this->status = ContentStatus::Published;
    }

    public function archive(): void
    {
        $this->status = ContentStatus::Archived;
    }

    public function markDeleted(): void
    {
        $this->status = ContentStatus::Deleted;
    }
}
