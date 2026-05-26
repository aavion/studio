<?php

declare(strict_types=1);

namespace App\View\Injection;

use App\Content\Routing\ContentRoutePath;
use App\Core\Access\AccessLevel;
use App\Core\Message\MessageKey;
use App\Core\Validation\Identifier;

final readonly class DynamicViewInjection
{
    /**
     * @param list<string> $accessGroups
     * @param array<string, mixed> $templateContext
     */
    public function __construct(
        private string $uid,
        private ViewSurface $surface,
        private DynamicViewInjectionSlot $slot,
        private string $template,
        private DynamicViewInjectionFilter $filter = new DynamicViewInjectionFilter(),
        private int $sortOrder = 0,
        private ?string $variantSlug = null,
        private ?string $label = null,
        private ?int $accessLevel = null,
        private array $accessGroups = [],
        private array $templateContext = [],
    ) {
        null === $variantSlug || $this->normalizeVariantSlug($variantSlug);
        null === $accessLevel || AccessLevel::assert($accessLevel);
        foreach ($accessGroups as $group) {
            Identifier::assertSnakeCase($group, MessageKey::ACCESS_GROUP_IDENTIFIER_INVALID, '%identifier%');
        }
    }

    public function uid(): string
    {
        return $this->uid;
    }

    public function surface(): ViewSurface
    {
        return $this->surface;
    }

    public function slot(): DynamicViewInjectionSlot
    {
        return $this->slot;
    }

    public function template(): string
    {
        return $this->template;
    }

    public function filter(): DynamicViewInjectionFilter
    {
        return $this->filter;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    public function variantSlug(): ?string
    {
        return $this->variantSlug;
    }

    public function label(): ?string
    {
        return $this->label;
    }

    public function accessLevel(): ?int
    {
        return $this->accessLevel;
    }

    /**
     * @return list<string>
     */
    public function accessGroups(): array
    {
        return array_values(array_unique($this->accessGroups));
    }

    /**
     * @return array<string, mixed>
     */
    public function templateContext(): array
    {
        return $this->templateContext;
    }

    private function normalizeVariantSlug(string $variantSlug): string
    {
        return ContentRoutePath::fromPath('/~/~'.$variantSlug)->variant() ?? $variantSlug;
    }
}
