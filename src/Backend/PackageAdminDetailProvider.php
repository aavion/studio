<?php

declare(strict_types=1);

namespace App\Backend;

use App\Core\Access\AccessActor;
use App\Core\AdminAcl\AdminFeatureAccessPolicy;
use App\Core\AdminAcl\AdminPermissionState;
use App\Core\Package\ExtensionPackageStatus;
use App\Entity\ExtensionPackage;
use App\Entity\UserAccount;
use App\View\SystemPackageMetadataProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

final readonly class PackageAdminDetailProvider
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SystemPackageMetadataProvider $systemPackageMetadata,
        private PackageAdminFileReader $fileReader,
        private PackageAdminLinkResolver $linkResolver,
        private PackageDependencyLabelParser $dependencyLabelParser,
        private Security $security,
        private AdminFeatureAccessPolicy $adminAcl,
    ) {
    }

    public function package(string $packageName): ?array
    {
        if ('system' === $packageName) {
            return $this->systemPackage();
        }

        $package = $this->entityManager->getRepository(ExtensionPackage::class)->findOneBy([
            'packageName' => $packageName,
        ]);

        return $package instanceof ExtensionPackage ? $this->extensionPackage($package) : null;
    }

    private function systemPackage(): array
    {
        $metadata = $this->systemPackageMetadata->metadata();

        return [
            'package_name' => 'system',
            'label' => $metadata['name'],
            'description' => $metadata['description'],
            'author' => $metadata['author'],
            'path' => '.',
            'status' => ExtensionPackageStatus::Active->value,
            'status_label_key' => 'admin.packages.status.active',
            'status_tone' => 'success',
            'immutable' => true,
            'scopes' => $this->scopeRows($metadata['scopes']),
            'manifest_version' => $metadata['version'],
            'installed_version' => null,
            'license' => $metadata['license'] ?? null,
            'dependencies' => [],
            'homepage' => $metadata['homepage'] ?? null,
            'homepage_url' => $this->linkResolver->safeExternalUrl($metadata['homepage'] ?? null),
            'source' => $metadata['source'] ?? null,
            'source_url' => $this->linkResolver->sourceUrl($metadata['source'] ?? null, $metadata['channel'] ?? null),
            'readme' => $this->fileReader->readReadme('.'),
            'preview_image' => $this->fileReader->previewImageDataUri('.', $metadata['image'] ?? null),
            'actions' => [],
        ];
    }

    private function extensionPackage(ExtensionPackage $package): array
    {
        $metadata = $package->metadata();
        $manifest = $this->fileReader->readManifest($package->path());
        $label = $this->metadataString($metadata, 'display_name') ?? $package->packageName();
        $dependencies = $manifest?->get('PACKAGE_DEPENDENCIES') ?? $this->metadataString($metadata, 'dependencies');
        $source = $this->metadataString($metadata, 'source') ?? $manifest?->get('PACKAGE_SOURCE');
        $channel = $this->metadataString($metadata, 'channel') ?? $manifest?->get('PACKAGE_CHANNEL');

        return [
            'package_name' => $package->packageName(),
            'label' => $label,
            'description' => $this->metadataString($metadata, 'description') ?? $manifest?->get('PACKAGE_DESCRIPTION'),
            'author' => $this->metadataString($metadata, 'author') ?? $manifest?->get('PACKAGE_AUTHOR'),
            'path' => $package->path(),
            'status' => $package->status()->value,
            'status_label_key' => 'admin.packages.status.'.$package->status()->value,
            'status_tone' => $this->statusTone($package->status()),
            'immutable' => false,
            'scopes' => $this->scopeRows($package->scopeValues()),
            'manifest_version' => $package->manifestVersion(),
            'installed_version' => $package->installedVersion(),
            'license' => $this->metadataString($metadata, 'license') ?? $manifest?->get('PACKAGE_LICENSE'),
            'dependencies' => $this->dependencyLabelParser->parse($dependencies),
            'homepage' => $this->metadataString($metadata, 'homepage') ?? $manifest?->get('PACKAGE_HOMEPAGE'),
            'homepage_url' => $this->linkResolver->safeExternalUrl($this->metadataString($metadata, 'homepage') ?? $manifest?->get('PACKAGE_HOMEPAGE')),
            'source' => $source,
            'source_url' => $this->linkResolver->sourceUrl($source, $channel),
            'readme' => $this->fileReader->readReadme($package->path()),
            'preview_image' => $this->fileReader->previewImageDataUri($package->path(), $this->metadataString($metadata, 'image') ?? $manifest?->get('PACKAGE_IMAGE')),
            'actions' => $this->actions($package),
        ];
    }

    /**
     * @param list<string> $scopes
     *
     * @return list<array{value: string, label_key: string}>
     */
    private function scopeRows(array $scopes): array
    {
        return array_map(static fn (string $scope): array => [
            'value' => $scope,
            'label_key' => 'admin.packages.scope.'.str_replace('-', '_', $scope),
        ], $scopes);
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
            ExtensionPackageStatus::Inactive => [$this->action($package, PackageLifecycleAdmin::ACTION_ACTIVATE, 'primary', $state)],
            ExtensionPackageStatus::Active => [$this->action($package, PackageLifecycleAdmin::ACTION_DEACTIVATE, 'secondary', $state)],
            ExtensionPackageStatus::Faulty => [$this->action($package, PackageLifecycleAdmin::ACTION_RESET_FAULT, 'secondary', $state)],
            ExtensionPackageStatus::Removed => [],
        };

        $cleanupActions = ExtensionPackageStatus::Removed === $package->status()
            ? [$this->action($package, PackageLifecycleAdmin::ACTION_PURGE, 'danger', $state)]
            : [];

        if (ExtensionPackageStatus::Removed !== $package->status()) {
            $cleanupActions[] = $this->action($package, PackageLifecycleAdmin::ACTION_DELETE, 'danger', $state);
        }

        return [...$stateActions, ...$cleanupActions];
    }

    private function action(ExtensionPackage $package, string $action, string $variant, AdminPermissionState $state): array
    {
        return [
            'id' => $action,
            'label_key' => 'admin.packages.lifecycle.'.$this->actionKey($action).'.label',
            'path' => $this->actionPath($package->packageName(), $action),
            'variant' => $variant,
            'disabled' => !$state->isMutable(),
        ];
    }

    private function actionPath(string $packageName, string $action): string
    {
        return '/admin/packages/'.rawurlencode($packageName).'/'.$action;
    }

    private function actionKey(string $action): string
    {
        return str_replace('-', '_', $action);
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
