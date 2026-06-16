<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Access\AccessActor;
use App\Core\AdminAcl\AdminFeatureAccessPolicy;
use App\Core\AdminAcl\AdminPermissionState;
use App\Core\Package\Settings\PackageSettingRegistry;
use App\Entity\ExtensionPackage;
use App\Entity\UserAccount;
use App\View\SystemPackageMetadataProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

final readonly class PackageAdminOverview
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PackageSettingRegistry $settingRegistry,
        private SystemPackageMetadataProvider $systemPackageMetadata,
        private Security $security,
        private AdminFeatureAccessPolicy $adminAcl,
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
            static fn (mixed $package): bool => $package instanceof ExtensionPackage && 'system' !== $package->packageName(),
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

        return [
            $this->systemRow(),
            ...array_map(
                fn (ExtensionPackage $package): array => $this->row($package, $settingPackages[$package->packageName()]['path'] ?? null),
                $packages,
            ),
        ];
    }

    private function row(ExtensionPackage $package, ?string $settingsPath): array
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
            'actions' => $this->actions($package),
            'scopes' => array_map(static fn (string $scope): array => [
                'value' => $scope,
                'label_key' => 'admin.packages.scope.'.str_replace('-', '_', $scope),
            ], $package->scopeValues()),
            'manifest_version' => $package->manifestVersion(),
            'installed_version' => $package->installedVersion(),
            'available_version' => $package->availableVersion(),
            'settings_path' => $settingsPath,
        ];
    }

    private function systemRow(): array
    {
        $metadata = $this->systemPackageMetadata->metadata();
        $version = $metadata['version'] ?? null;

        return [
            'package_name' => 'system',
            'label' => $metadata['name'],
            'label_key' => null,
            'detail_path' => $this->detailPath('system'),
            'description' => $metadata['description'],
            'description_key' => null,
            'author' => $metadata['author'],
            'path' => '.',
            'immutable' => true,
            'status' => ExtensionPackageStatus::Active->value,
            'status_label_key' => 'admin.packages.status.active',
            'status_tone' => 'success',
            'actions' => [],
            'scopes' => array_map(static fn (string $scope): array => [
                'value' => $scope,
                'label_key' => 'admin.packages.scope.'.str_replace('-', '_', $scope),
            ], $metadata['scopes']),
            'manifest_version' => is_string($version) && '' !== trim($version) ? $version : null,
            'installed_version' => null,
            'available_version' => null,
            'settings_path' => null,
        ];
    }

    private function detailPath(string $packageName): string
    {
        return '/admin/packages/'.rawurlencode($packageName);
    }

    /**
     * @return list<array{id: string, label_key: string, path: string, variant: string}>
     */
    private function actions(ExtensionPackage $package): array
    {
        $state = $this->adminAcl->state('admin.packages', $this->actor());

        if (!$state->isVisible()) {
            return [];
        }

        $stateActions = match ($package->status()) {
            ExtensionPackageStatus::Inactive => [$this->action($package, 'activate', 'primary', $state)],
            ExtensionPackageStatus::Active => [$this->action($package, 'deactivate', 'secondary', $state)],
            ExtensionPackageStatus::Faulty => [$this->action($package, 'reset-fault', 'secondary', $state)],
            ExtensionPackageStatus::Removed => [],
        };

        $cleanupActions = ExtensionPackageStatus::Removed === $package->status()
            ? [$this->action($package, 'purge', 'danger', $state)]
            : [];

        if (ExtensionPackageStatus::Removed !== $package->status()) {
            $cleanupActions[] = $this->action($package, 'delete', 'danger', $state);
        }

        return [...$stateActions, ...$cleanupActions];
    }

    private function action(ExtensionPackage $package, string $action, string $variant, AdminPermissionState $state): array
    {
        return [
            'id' => $action,
            'label_key' => 'admin.packages.lifecycle.'.str_replace('-', '_', $action).'.label',
            'path' => $this->detailPath($package->packageName()).'/'.$action,
            'variant' => $variant,
            'disabled' => !$state->isMutable(),
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

    private function actor(): AccessActor
    {
        $user = $this->security->getUser();

        return $user instanceof UserAccount ? AccessActor::fromUserAccount($user) : AccessActor::anonymous();
    }
}
