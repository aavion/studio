<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Package\Settings\PackageSettingRegistry;
use App\Entity\ExtensionPackage;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PackageAdminOverview
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PackageSettingRegistry $settingRegistry,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function packages(): array
    {
        $settingPackages = $this->settingRegistry->packagesWithDefinitions();
        $packages = array_filter(
            $this->entityManager->getRepository(ExtensionPackage::class)->findAll(),
            static fn (mixed $package): bool => $package instanceof ExtensionPackage,
        );

        usort(
            $packages,
            static fn (ExtensionPackage $left, ExtensionPackage $right): int => [
                $left->status()->value,
                $left->packageName(),
            ] <=> [
                $right->status()->value,
                $right->packageName(),
            ],
        );

        return array_map(
            fn (ExtensionPackage $package): array => $this->row($package, $settingPackages[$package->packageName()]['path'] ?? null),
            $packages,
        );
    }

    private function row(ExtensionPackage $package, ?string $settingsPath): array
    {
        $metadata = $package->metadata();
        $label = $this->metadataString($metadata, 'display_name') ?? $package->packageName();

        return [
            'package_name' => $package->packageName(),
            'label' => $label,
            'description' => $this->metadataString($metadata, 'description'),
            'path' => $package->path(),
            'status' => $package->status()->value,
            'status_label_key' => 'admin.packages.status.'.$package->status()->value,
            'status_tone' => $this->statusTone($package->status()),
            'scopes' => array_map(static fn (string $scope): array => [
                'value' => $scope,
                'label_key' => 'admin.packages.scope.'.str_replace('-', '_', $scope),
            ], $package->scopeValues()),
            'manifest_version' => $package->manifestVersion(),
            'installed_version' => $package->installedVersion(),
            'settings_path' => $settingsPath,
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function metadataString(array $metadata, string $key): ?string
    {
        $value = $metadata[$key] ?? null;

        return is_string($value) && '' !== trim($value) ? $value : null;
    }

    private function statusTone(ExtensionPackageStatus $status): string
    {
        return match ($status) {
            ExtensionPackageStatus::Active => 'success',
            ExtensionPackageStatus::Inactive => 'neutral',
            ExtensionPackageStatus::Removed => 'warning',
            ExtensionPackageStatus::Faulty => 'error',
        };
    }
}
