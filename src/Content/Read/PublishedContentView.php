<?php

declare(strict_types=1);

namespace App\Content\Read;

use App\Core\Access\AccessDecision;
use App\Entity\ContentItem;
use App\Entity\ContentRevision;

final readonly class PublishedContentView
{
    /**
     * @param array<string, mixed> $fields
     */
    public function __construct(
        private ContentItem $content,
        private ContentRevision $revision,
        private ContentReadContext $context,
        private array $fields,
        private AccessDecision $accessDecision,
    ) {
    }

    public function content(): ContentItem
    {
        return $this->content;
    }

    public function revision(): ContentRevision
    {
        return $this->revision;
    }

    public function context(): ContentReadContext
    {
        return $this->context;
    }

    /**
     * @return array<string, mixed>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    public function field(string $identifier): mixed
    {
        return $this->fields[$identifier] ?? null;
    }

    public function title(): ?string
    {
        $title = $this->field('title');

        return is_string($title) ? $title : null;
    }

    public function subtitle(): ?string
    {
        $subtitle = $this->field('subtitle');

        return is_string($subtitle) ? $subtitle : null;
    }

    public function accessDecision(): AccessDecision
    {
        return $this->accessDecision;
    }
}
