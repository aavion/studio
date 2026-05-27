<?php

declare(strict_types=1);

namespace App\View\Injection;

final readonly class ConfigurableStaticViewInjectionRoute
{
    /**
     * @param list<string> $accessGroups
     * @param array<string, mixed> $linkAttributes
     */
    public function __construct(
        private string $uid,
        private string $relativePathSlug,
        private string $label,
        private string $template,
        private int $sortOrder = 0,
        private ?bool $menu = true,
        private ?int $accessLevel = null,
        private array $accessGroups = [],
        private array $linkAttributes = [],
    ) {
    }

    public function toStaticViewInjection(ViewSurface $surface, string $baseSlug): StaticViewInjection
    {
        $relativePathSlug = trim($this->relativePathSlug, '/');
        $pathSlug = '' === $relativePathSlug ? $baseSlug : trim($baseSlug, '/').'/'.$relativePathSlug;
        $parentSlug = '' === $relativePathSlug ? null : $baseSlug;

        return new StaticViewInjection(
            $this->uid,
            $surface,
            $pathSlug,
            $this->label,
            $this->template,
            parentSlug: $parentSlug,
            sortOrder: $this->sortOrder,
            menu: $this->menu,
            accessLevel: $this->accessLevel,
            accessGroups: $this->accessGroups,
            linkAttributes: $this->linkAttributes,
        );
    }
}
