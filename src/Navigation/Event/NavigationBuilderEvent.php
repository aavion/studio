<?php

declare(strict_types=1);

namespace App\Navigation\Event;

use App\Core\Event\PublicEventInterface;
use App\Navigation\NavigationItem;
use Symfony\Contracts\EventDispatcher\Event;

final class NavigationBuilderEvent extends Event implements PublicEventInterface
{
    /**
     * @param list<NavigationItem> $items
     */
    public function __construct(
        private readonly string $identifier,
        private readonly string $language,
        private readonly int $maxDepth,
        private readonly int $startLevel,
        private readonly ?string $rootUid,
        private array $items,
    ) {
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function language(): string
    {
        return $this->language;
    }

    public function maxDepth(): int
    {
        return $this->maxDepth;
    }

    public function startLevel(): int
    {
        return $this->startLevel;
    }

    public function rootUid(): ?string
    {
        return $this->rootUid;
    }

    /**
     * @return list<NavigationItem>
     */
    public function items(): array
    {
        return $this->items;
    }

    public function addItem(NavigationItem $item): void
    {
        $this->items[] = $item;
    }

    /**
     * @param list<NavigationItem> $items
     */
    public function setItems(array $items): void
    {
        $this->items = array_values($items);
    }
}
