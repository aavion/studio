<?php

declare(strict_types=1);

namespace App\Backend;

final readonly class BackendViewDefinition
{
    /**
     * @param array<string, mixed> $routeParameters
     * @param array<string, mixed> $linkAttributes
     * @param list<string> $accessGroups
     * @param array<string, mixed> $context
     */
    public function __construct(
        private string $uid,
        private BackendArea $area,
        private string $path,
        private string $label,
        private string $template,
        private int $sortOrder = 0,
        private ?string $parentUid = null,
        private ?int $minimumAccessLevel = null,
        private array $routeParameters = [],
        private array $linkAttributes = [],
        private array $accessGroups = [],
        private array $context = [],
    ) {
    }

    public function uid(): string
    {
        return $this->uid;
    }

    public function area(): BackendArea
    {
        return $this->area;
    }

    public function path(): string
    {
        return trim($this->path, '/');
    }

    public function label(): string
    {
        return $this->label;
    }

    public function template(): string
    {
        return $this->template;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    public function parentUid(): ?string
    {
        return $this->parentUid;
    }

    public function minimumAccessLevel(): int
    {
        return $this->minimumAccessLevel ?? $this->area->minimumAccessLevel();
    }

    /**
     * @return list<string>
     */
    public function accessGroups(): array
    {
        return $this->accessGroups;
    }

    public function routeName(): string
    {
        return '' === $this->path() ? $this->area->routeName() : 'backend_'.$this->area->value.'_route';
    }

    /**
     * @return array<string, mixed>
     */
    public function routeParameters(): array
    {
        return '' === $this->path()
            ? $this->routeParameters
            : ['path' => $this->path()] + $this->routeParameters;
    }

    /**
     * @return array<string, mixed>
     */
    public function linkAttributes(): array
    {
        return $this->linkAttributes;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    public function accessFeature(): ?string
    {
        $feature = $this->context['access_feature'] ?? null;

        return is_string($feature) && '' !== $feature ? $feature : null;
    }
}
