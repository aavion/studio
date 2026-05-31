<?php

declare(strict_types=1);

namespace App\View\Injection;

use App\Content\Routing\ContentRoutePath;
use App\Content\Routing\ContentSlug;
use App\Core\Access\AccessLevel;
use App\Core\Validation\Identifier;

final readonly class StaticViewInjection
{
    /**
     * @param list<string> $accessGroups
     * @param array<string, mixed> $linkAttributes
     */
    public function __construct(
        private string $uid,
        private ViewSurface $surface,
        private string $pathSlug,
        private string $label,
        private string $template,
        private ?string $parentSlug = null,
        private int $sortOrder = 0,
        private ?string $variantSlug = null,
        private ?bool $menu = true,
        private ?int $accessLevel = null,
        private array $accessGroups = [],
        private array $linkAttributes = [],
    ) {
        $this->normalizePathSlug($pathSlug);
        null === $parentSlug || '/' === $parentSlug || $this->normalizePathSlug($parentSlug);
        null === $variantSlug || $this->normalizeVariantSlug($variantSlug);
        null === $accessLevel || AccessLevel::assert($accessLevel);
        foreach ($accessGroups as $group) {
            Identifier::assertAclGroupIdentifier($group);
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

    public function pathSlug(): string
    {
        return trim($this->pathSlug, '/');
    }

    public function routePath(): string
    {
        $path = '' === $this->pathSlug() ? '/' : '/'.$this->pathSlug();

        if (null === $this->variantSlug) {
            return $path;
        }

        return rtrim($path, '/').'/~'.$this->variantSlug;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function template(): string
    {
        return $this->template;
    }

    public function parentSlug(): ?string
    {
        if (null === $this->parentSlug || '/' === $this->parentSlug) {
            return null;
        }

        return trim($this->parentSlug, '/');
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    public function variantSlug(): ?string
    {
        return $this->variantSlug;
    }

    public function menuVisible(): bool
    {
        return true === $this->menu;
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
    public function linkAttributes(): array
    {
        return $this->linkAttributes;
    }

    private function normalizePathSlug(string $pathSlug): string
    {
        $pathSlug = trim($pathSlug, '/');

        if ('' === $pathSlug) {
            return '';
        }

        foreach (explode('/', $pathSlug) as $segment) {
            ContentSlug::fromString($segment);
        }

        return $pathSlug;
    }

    private function normalizeVariantSlug(string $variantSlug): string
    {
        return ContentRoutePath::fromPath('/~/~'.$variantSlug)->variant() ?? $variantSlug;
    }
}
