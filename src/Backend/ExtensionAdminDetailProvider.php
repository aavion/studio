<?php

declare(strict_types=1);

namespace App\Backend;

use App\Core\Access\AccessActor;
use App\Core\AdminAcl\AdminFeatureAccessPolicy;
use App\Core\AdminAcl\AdminPermissionState;
use App\Core\Extension\ExtensionOperationRegistration;
use App\Core\Extension\ExtensionPhpLoader;
use App\Core\Extension\ExtensionRuntimeContributionRegistry;
use App\Core\Extension\ExtensionStatus;
use App\Entity\Extension;
use App\Entity\UserAccount;
use App\View\SystemExtensionMetadataProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

final readonly class ExtensionAdminDetailProvider
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SystemExtensionMetadataProvider $systemExtensionMetadata,
        private ExtensionAdminFileReader $fileReader,
        private ExtensionAdminLinkResolver $linkResolver,
        private ExtensionDependencyLabelParser $dependencyLabelParser,
        private Security $security,
        private AdminFeatureAccessPolicy $adminAcl,
        private ?ExtensionRuntimeContributionRegistry $runtimeContributions = null,
        private ?ExtensionPhpLoader $extensionPhpLoader = null,
    ) {
    }

    public function extension(string $extensionName): ?array
    {
        if ('system' === $extensionName) {
            return $this->systemExtension();
        }

        $extension = $this->entityManager->getRepository(Extension::class)->findOneBy([
            'extensionName' => $extensionName,
        ]);

        return $extension instanceof Extension ? $this->extensionExtension($extension) : null;
    }

    private function systemExtension(): array
    {
        $metadata = $this->systemExtensionMetadata->metadata();

        return [
            'extension_name' => 'system',
            'label' => $metadata['name'],
            'description' => $metadata['description'],
            'author' => $metadata['author'],
            'path' => '.',
            'status' => ExtensionStatus::Active->value,
            'status_label_key' => 'admin.extensions.status.active',
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

    private function extensionExtension(Extension $extension): array
    {
        $metadata = $extension->metadata();
        $manifest = $this->fileReader->readManifest($extension->path());
        $label = $this->metadataString($metadata, 'display_name') ?? $extension->extensionName();
        $dependencies = $manifest?->get('EXTENSION_DEPENDENCIES') ?? $this->metadataString($metadata, 'dependencies');
        $source = $this->metadataString($metadata, 'source') ?? $manifest?->get('EXTENSION_SOURCE');
        $channel = $this->metadataString($metadata, 'channel') ?? $manifest?->get('EXTENSION_CHANNEL');

        return [
            'extension_name' => $extension->extensionName(),
            'label' => $label,
            'description' => $this->metadataString($metadata, 'description') ?? $manifest?->get('EXTENSION_DESCRIPTION'),
            'author' => $this->metadataString($metadata, 'author') ?? $manifest?->get('EXTENSION_AUTHOR'),
            'path' => $extension->path(),
            'status' => $extension->status()->value,
            'status_label_key' => 'admin.extensions.status.'.$extension->status()->value,
            'status_tone' => $this->statusTone($extension->status()),
            'immutable' => false,
            'scopes' => $this->scopeRows($extension->scopeValues()),
            'manifest_version' => $extension->manifestVersion(),
            'installed_version' => $extension->installedVersion(),
            'license' => $this->metadataString($metadata, 'license') ?? $manifest?->get('EXTENSION_LICENSE'),
            'dependencies' => $this->dependencyLabelParser->parse($dependencies),
            'homepage' => $this->metadataString($metadata, 'homepage') ?? $manifest?->get('EXTENSION_HOMEPAGE'),
            'homepage_url' => $this->linkResolver->safeExternalUrl($this->metadataString($metadata, 'homepage') ?? $manifest?->get('EXTENSION_HOMEPAGE')),
            'source' => $source,
            'source_url' => $this->linkResolver->sourceUrl($source, $channel),
            'readme' => $this->fileReader->readReadme($extension->path()),
            'preview_image' => $this->fileReader->previewImageDataUri($extension->path(), $this->metadataString($metadata, 'image') ?? $manifest?->get('EXTENSION_IMAGE')),
            'actions' => $this->actions($extension),
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
            'label_key' => 'admin.extensions.scope.'.str_replace('-', '_', $scope),
        ], $scopes);
    }

    /**
     * @return list<array{id: string, label_key: string, path: string, variant: string}>
     */
    private function actions(Extension $extension): array
    {
        $state = $this->adminAcl->state('admin.extensions', $this->actor());

        if (!$state->isVisible()) {
            return [];
        }

        $stateActions = match ($extension->status()) {
            ExtensionStatus::Inactive => [$this->action($extension, ExtensionLifecycleAdmin::ACTION_ACTIVATE, 'primary', $state)],
            ExtensionStatus::Active => [$this->action($extension, ExtensionLifecycleAdmin::ACTION_DEACTIVATE, 'secondary', $state)],
            ExtensionStatus::Faulty => [$this->action($extension, ExtensionLifecycleAdmin::ACTION_RESET_FAULT, 'secondary', $state)],
            ExtensionStatus::Removed => [],
        };

        $cleanupActions = ExtensionStatus::Removed === $extension->status()
            ? [$this->action($extension, ExtensionLifecycleAdmin::ACTION_PURGE, 'danger', $state)]
            : [];

        if (ExtensionStatus::Removed !== $extension->status()) {
            $cleanupActions[] = $this->action($extension, ExtensionLifecycleAdmin::ACTION_DELETE, 'danger', $state);
        }

        return [...$stateActions, ...$this->operationActions($extension, $state), ...$cleanupActions];
    }

    private function action(Extension $extension, string $action, string $variant, AdminPermissionState $state): array
    {
        return [
            'id' => $action,
            'label_key' => 'admin.extensions.lifecycle.'.$this->actionKey($action).'.label',
            'path' => $this->actionPath($extension->extensionName(), $action),
            'variant' => $variant,
            'disabled' => !$state->isMutable(),
        ];
    }

    /**
     * @return list<array{id: string, label_key: string, path: string, variant: string, disabled: bool, live: bool, target: string}>
     */
    private function operationActions(Extension $extension, AdminPermissionState $state): array
    {
        if (ExtensionStatus::Active !== $extension->status() || null === $this->runtimeContributions) {
            return [];
        }

        $this->extensionPhpLoader?->loadActiveExtensions();

        return array_map(
            fn (ExtensionOperationRegistration $operation): array => [
                'id' => 'operation-'.strtr($operation->target(), '.:', '__'),
                'label_key' => $operation->definition()->labelKey(),
                'path' => '/admin/extensions/'.rawurlencode($extension->extensionName()),
                'variant' => 'secondary',
                'disabled' => !$state->isMutable(),
                'live' => true,
                'target' => $operation->target(),
            ],
            $this->runtimeContributions->extensionOperations($extension->extensionName()),
        );
    }

    private function actionPath(string $extensionName, string $action): string
    {
        return '/admin/extensions/'.rawurlencode($extensionName).'/'.$action;
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

    private function statusTone(ExtensionStatus $status): string
    {
        return match ($status) {
            ExtensionStatus::Active => 'success',
            ExtensionStatus::Inactive => 'neutral',
            ExtensionStatus::Removed => 'warning',
            ExtensionStatus::Faulty => 'error',
        };
    }

    private function actor(): AccessActor
    {
        $user = $this->security->getUser();

        return $user instanceof UserAccount ? AccessActor::fromUserAccount($user) : AccessActor::anonymous();
    }

}
