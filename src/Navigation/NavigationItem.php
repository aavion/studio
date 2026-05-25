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
        );
    }

    /**
     * @return array{uid: string, label: string, target_type: string, target_value: string, url: string, parent_uid: ?string, sort_order: int, level: int, metadata: array<string, mixed>, children: list<array<string, mixed>>}
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
            'metadata' => $this->metadata,
            'children' => array_map(
                static fn (NavigationItem $item): array => $item->toArray($level + 1),
                $this->children,
            ),
        ];
    }

    private function url(): string
    {
        return match ($this->targetType) {
            'url', 'content' => $this->targetValue,
            default => '#',
        };
    }
}
