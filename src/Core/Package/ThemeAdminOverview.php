<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Entity\ExtensionPackage;
use App\View\SystemPackageMetadataProvider;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ThemeAdminOverview
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SystemPackageMetadataProvider $systemPackageMetadata,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function sections(): array
    {
        return [
            $this->section('frontend', PackageScope::FrontendTheme, 'templates/frontend'),
            $this->section('backend', PackageScope::BackendTheme, 'templates/backend'),
        ];
    }

    private function section(string $key, PackageScope $scope, string $systemPath): array
    {
        $packages = $this->packages($scope);
        $themes = [
            $this->systemThemeRow($scope, $systemPath, !$this->hasActivePackage($packages)),
            ...array_map($this->packageRow(...), $packages),
        ];

        return [
            'key' => $key,
            'title_key' => 'admin.themes.sections.'.$key.'.title',
            'text_key' => 'admin.themes.sections.'.$key.'.text',
            'themes' => $themes,
        ];
    }

    /**
     * @return list<ExtensionPackage>
     */
    private function packages(PackageScope $scope): array
    {
        $packages = array_filter(
            $this->entityManager->getRepository(ExtensionPackage::class)->findAll(),
            static fn (mixed $package): bool => $package instanceof ExtensionPackage
                && 'system' !== $package->packageName()
                && $package->hasScope($scope),
        );

        usort(
            $packages,
            static fn (ExtensionPackage $left, ExtensionPackage $right): int => [
                self::statusSort($left->status()),
                $left->packageName(),
            ] <=> [
                self::statusSort($right->status()),
                $right->packageName(),
            ],
        );

        return array_values($packages);
    }

    private function packageRow(ExtensionPackage $package): array
    {
        $metadata = $package->metadata();
        $label = $this->metadataString($metadata, 'display_name') ?? $package->packageName();

        return [
            'package_name' => $package->packageName(),
            'label' => $label,
            'label_key' => null,
            'detail_path' => $this->detailPath($package->packageName()),
            'description' => $this->metadataString($metadata, 'description'),
            'description_key' => null,
            'author' => $this->metadataString($metadata, 'author'),
            'path' => $package->path(),
            'immutable' => false,
            'status' => $package->status()->value,
            'status_label_key' => 'admin.packages.status.'.$package->status()->value,
            'status_tone' => $this->statusTone($package->status()),
            'status_action_path' => $this->statusActionPath($package),
            'type_label_key' => 'admin.themes.type.package',
            'type_tone' => 'neutral',
            'version' => $package->installedVersion() ?? $package->manifestVersion(),
        ];
    }

    private function systemThemeRow(PackageScope $scope, string $path, bool $active): array
    {
        $metadata = $this->systemPackageMetadata->metadata();
        $version = $metadata['version'] ?? null;
        $status = $active ? ExtensionPackageStatus::Active : ExtensionPackageStatus::Inactive;

        return [
            'package_name' => 'system',
            'label' => $metadata['name'],
            'label_key' => null,
            'detail_path' => $this->detailPath('system'),
            'description' => $metadata['description'],
            'description_key' => null,
            'author' => $metadata['author'],
            'path' => $path,
            'immutable' => true,
            'status' => $status->value,
            'status_label_key' => 'admin.packages.status.'.$status->value,
            'status_tone' => $this->statusTone($status),
            'status_action_path' => null,
            'type_label_key' => 'admin.themes.type.system',
            'type_tone' => 'info',
            'version' => is_string($version) && '' !== trim($version) ? $version : null,
            'scope' => $scope->value,
        ];
    }

    private function detailPath(string $packageName): string
    {
        return '/admin/packages/'.rawurlencode($packageName);
    }

    private function statusActionPath(ExtensionPackage $package): ?string
    {
        $action = match ($package->status()) {
            ExtensionPackageStatus::Inactive => 'activate',
            ExtensionPackageStatus::Active => 'deactivate',
            ExtensionPackageStatus::Faulty => 'reset-fault',
            ExtensionPackageStatus::Removed => null,
        };

        return null === $action ? null : $this->detailPath($package->packageName()).'/'.$action;
    }

    /**
     * @param list<ExtensionPackage> $packages
     */
    private function hasActivePackage(array $packages): bool
    {
        foreach ($packages as $package) {
            if (ExtensionPackageStatus::Active === $package->status()) {
                return true;
            }
        }

        return false;
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

    private static function statusSort(ExtensionPackageStatus $status): int
    {
        return match ($status) {
            ExtensionPackageStatus::Active => 0,
            ExtensionPackageStatus::Inactive => 1,
            ExtensionPackageStatus::Faulty => 2,
            ExtensionPackageStatus::Removed => 3,
        };
    }
}
