<?php

declare(strict_types=1);

namespace App\Core\Package\Settings;

use App\Core\Package\ActivePackageProviderInterface;
use App\Entity\ExtensionPackage;
use Throwable;

final readonly class PackageSettingRegistry
{
    /**
     * @param iterable<PackageSettingProviderInterface> $providers
     */
    public function __construct(
        private iterable $providers,
        private ActivePackageProviderInterface $activePackageProvider,
    ) {
    }

    /**
     * @return list<PackageSettingDefinition>
     */
    public function definitions(?string $packageName = null, bool $activeOnly = true): array
    {
        $activePackages = $activeOnly ? $this->activePackageNames() : null;
        $definitions = [];

        foreach ($this->providers as $provider) {
            foreach ($provider->packageSettings() as $definition) {
                if (null !== $packageName && $definition->packageName() !== $packageName) {
                    continue;
                }

                if (null !== $activePackages && !isset($activePackages[$definition->packageName()])) {
                    continue;
                }

                $definitions[] = $definition;
            }
        }

        usort(
            $definitions,
            static fn (PackageSettingDefinition $left, PackageSettingDefinition $right): int => [
                $left->packageName(),
                $left->sortOrder(),
                $left->label(),
                $left->key(),
            ] <=> [
                $right->packageName(),
                $right->sortOrder(),
                $right->label(),
                $right->key(),
            ],
        );

        return $definitions;
    }

    /**
     * @return array<string, array{label: string, description: string|null}>
     */
    public function packagesWithDefinitions(): array
    {
        $packages = $this->activePackageMetadata();
        $definitions = $this->definitions();
        $result = [];

        foreach ($definitions as $definition) {
            $packageName = $definition->packageName();
            $result[$packageName] = [
                'label' => $packages[$packageName]['label'] ?? $packageName,
                'description' => $packages[$packageName]['description'] ?? null,
            ];
        }

        ksort($result);

        return $result;
    }

    /**
     * @return array<string, true>
     */
    private function activePackageNames(): array
    {
        return array_fill_keys(array_keys($this->activePackageMetadata()), true);
    }

    /**
     * @return array<string, array{label: string, description: string|null}>
     */
    private function activePackageMetadata(): array
    {
        try {
            $packages = $this->activePackageProvider->packages();
        } catch (Throwable) {
            return [];
        }

        $metadata = [];

        foreach ($packages as $package) {
            if (!$package instanceof ExtensionPackage) {
                continue;
            }

            $packageMetadata = $package->metadata();
            $label = $packageMetadata['display_name'] ?? $package->packageName();
            $description = $packageMetadata['description'] ?? null;

            $metadata[$package->packageName()] = [
                'label' => is_string($label) && '' !== trim($label) ? $label : $package->packageName(),
                'description' => is_string($description) && '' !== trim($description) ? $description : null,
            ];
        }

        return $metadata;
    }
}
