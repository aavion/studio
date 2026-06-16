<?php

declare(strict_types=1);

namespace App\Core\AdminAcl;

final readonly class AdminFeatureDefinition
{
    public function __construct(
        private string $identifier,
        private string $labelKey,
        private string $descriptionKey,
        private string $categoryKey,
        private AdminPermissionState $defaultState = AdminPermissionState::Denied,
        private AdminPermissionState $ownerState = AdminPermissionState::Mutable,
        private bool $configurable = true,
        private int $sortOrder = 0,
    ) {
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function surface(): AdminPermissionSurface
    {
        return AdminPermissionSurface::fromFeatureIdentifier($this->identifier);
    }

    public function labelKey(): string
    {
        return $this->labelKey;
    }

    public function descriptionKey(): string
    {
        return $this->descriptionKey;
    }

    public function categoryKey(): string
    {
        return $this->categoryKey;
    }

    public function defaultState(): AdminPermissionState
    {
        return $this->defaultState;
    }

    public function ownerState(): AdminPermissionState
    {
        return $this->ownerState;
    }

    public function configurable(): bool
    {
        return $this->configurable;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }
}
