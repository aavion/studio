<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Access\AccessActor;
use App\Core\AdminAcl\AdminFeatureAccessPolicy;
use App\Core\AdminAcl\AdminPermissionState;
use App\Entity\ExtensionPackage;
use App\Entity\UserAccount;
use App\View\SystemPackageMetadataProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

final readonly class ThemeAdminOverview
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SystemPackageMetadataProvider $systemPackageMetadata,
        private Security $security,
        private AdminFeatureAccessPolicy $adminAcl,
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
        $state = $this->packageState();
        $packages = $this->packages($scope);
        $activePackage = $this->activePackage($packages);
        $themes = [
            $this->systemThemeRow($scope, $systemPath, null === $activePackage, $activePackage, $state),
            ...array_map(fn (ExtensionPackage $package): array => $this->packageRow($package, $state), $packages),
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
                && $package->hasScope($scope)
                && ExtensionPackageStatus::Removed !== $package->status(),
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

    private function packageRow(ExtensionPackage $package, AdminPermissionState $state): array
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
            'type_label_key' => 'admin.themes.type.package',
            'type_tone' => 'neutral',
            'version' => $package->installedVersion() ?? $package->manifestVersion(),
            'quick_action' => $state->isVisible() ? $this->quickAction($package, $state) : null,
        ];
    }

    private function systemThemeRow(PackageScope $scope, string $path, bool $active, ?ExtensionPackage $activePackage, AdminPermissionState $state): array
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
            'type_label_key' => 'admin.themes.type.system',
            'type_tone' => 'info',
            'version' => is_string($version) && '' !== trim($version) ? $version : null,
            'scope' => $scope->value,
            'quick_action' => $active
                ? $this->disabledQuickAction('admin.themes.quick.active', 'secondary')
                : (null === $activePackage || !$state->isVisible() ? null : $this->linkedQuickAction('admin.themes.quick.use', $this->detailPath($activePackage->packageName()).'/deactivate', 'primary', !$state->isMutable())),
        ];
    }

    private function detailPath(string $packageName): string
    {
        return '/admin/packages/'.rawurlencode($packageName);
    }

    /**
     * @param list<ExtensionPackage> $packages
     */
    private function activePackage(array $packages): ?ExtensionPackage
    {
        foreach ($packages as $package) {
            if (ExtensionPackageStatus::Active === $package->status()) {
                return $package;
            }
        }

        return null;
    }

    private function quickAction(ExtensionPackage $package, AdminPermissionState $state): ?array
    {
        return match ($package->status()) {
            ExtensionPackageStatus::Active => $this->disabledQuickAction('admin.themes.quick.active', 'secondary'),
            ExtensionPackageStatus::Inactive => $this->linkedQuickAction('admin.themes.quick.use', $this->detailPath($package->packageName()).'/activate', 'primary', !$state->isMutable()),
            ExtensionPackageStatus::Faulty => $this->linkedQuickAction('admin.themes.quick.repair', $this->detailPath($package->packageName()).'/reset-fault', 'secondary', !$state->isMutable()),
            ExtensionPackageStatus::Removed => null,
        };
    }

    private function linkedQuickAction(string $labelKey, string $path, string $variant, bool $disabled = false): array
    {
        return [
            'label_key' => $labelKey,
            'path' => $path,
            'variant' => $variant,
            'disabled' => $disabled,
        ];
    }

    private function disabledQuickAction(string $labelKey, string $variant): array
    {
        return [
            'label_key' => $labelKey,
            'path' => null,
            'variant' => $variant,
            'disabled' => true,
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

    private static function statusSort(ExtensionPackageStatus $status): int
    {
        return match ($status) {
            ExtensionPackageStatus::Active => 0,
            ExtensionPackageStatus::Inactive => 1,
            ExtensionPackageStatus::Faulty => 2,
            ExtensionPackageStatus::Removed => 3,
        };
    }

    private function packageState(): AdminPermissionState
    {
        return $this->adminAcl->state('admin.packages', $this->actor());
    }

    private function actor(): AccessActor
    {
        $user = $this->security->getUser();

        return $user instanceof UserAccount ? AccessActor::fromUserAccount($user) : AccessActor::anonymous();
    }
}
