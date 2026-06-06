<?php

declare(strict_types=1);

namespace App\Navigation;

final readonly class NavigationItem
{
    /**
     * @param array<string, mixed> $metadata
     * @param list<NavigationItem> $children
     */
    public function __construct(
        private string $uid,
        private string $label,
        private string $targetType,
        private string $targetValue,
        private ?string $parentUid = null,
        private int $sortOrder = 0,
        private array $metadata = [],
        private array $children = [],
        private ?string $resolvedUrl = null,
        private bool $active = false,
        private bool $activeAncestor = false,
    ) {
    }

    public function uid(): string
    {
        return $this->uid;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function targetType(): string
    {
        return $this->targetType;
    }

    public function targetValue(): string
    {
        return $this->targetValue;
    }

    public function parentUid(): ?string
    {
        return $this->parentUid;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    /**
     * @return list<NavigationItem>
     */
    public function children(): array
    {
        return $this->children;
    }

    public function resolvedUrl(): ?string
    {
        return $this->resolvedUrl;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function isActiveAncestor(): bool
    {
        return $this->activeAncestor;
    }

    /**
     * @param list<NavigationItem> $children
     */
    public function withChildren(array $children): self
    {
        return new self(
            $this->uid,
            $this->label,
            $this->targetType,
            $this->targetValue,
            $this->parentUid,
            $this->sortOrder,
            $this->metadata,
            $children,
            $this->resolvedUrl,
            $this->active,
            $this->activeAncestor,
        );
    }

    public function withResolvedUrl(?string $resolvedUrl): self
    {
        return new self(
            $this->uid,
            $this->label,
            $this->targetType,
            $this->targetValue,
            $this->parentUid,
            $this->sortOrder,
            $this->metadata,
            $this->children,
            $resolvedUrl,
            $this->active,
            $this->activeAncestor,
        );
    }

    public function withActiveState(bool $active, bool $activeAncestor): self
    {
        return new self(
            $this->uid,
            $this->label,
            $this->targetType,
            $this->targetValue,
            $this->parentUid,
            $this->sortOrder,
            $this->metadata,
            $this->children,
            $this->resolvedUrl,
            $active,
            $activeAncestor,
        );
    }

    /**
     * @return array{uid: string, label: string, target_type: string, target_value: string, url: string, parent_uid: ?string, sort_order: int, level: int, active: bool, active_ancestor: bool, metadata: array<string, mixed>, children: list<array<string, mixed>>}
     */
    public function toArray(int $level = 1): array
    {
        return [
            'uid' => $this->uid,
            'label' => $this->label,
            'target_type' => $this->targetType,
            'target_value' => $this->targetValue,
            'url' => $this->url(),
            'parent_uid' => $this->parentUid,
            'sort_order' => $this->sortOrder,
            'level' => $level,
            'active' => $this->active,
            'active_ancestor' => $this->activeAncestor,
            'metadata' => $this->metadata,
            'children' => array_map(
                static fn (NavigationItem $item): array => $item->toArray($level + 1),
                $this->children,
            ),
        ];
    }

    private function url(): string
    {
        if (null !== $this->resolvedUrl) {
            return $this->resolvedUrl;
        }

        return match ($this->targetType) {
            NavigationTargetType::URL, NavigationTargetType::CONTENT => $this->targetValue,
            default => '#',
        };
    }
}
