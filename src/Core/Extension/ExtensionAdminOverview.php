<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Access\AccessActor;
use App\Core\AdminAcl\AdminFeatureAccessPolicy;
use App\Core\AdminAcl\AdminPermissionState;
use App\Core\Extension\Settings\ExtensionSettingRegistry;
use App\Entity\Extension;
use App\Entity\UserAccount;
use App\View\SystemExtensionMetadataProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

final readonly class ExtensionAdminOverview
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ExtensionSettingRegistry $settingRegistry,
        private SystemExtensionMetadataProvider $systemExtensionMetadata,
        private Security $security,
        private AdminFeatureAccessPolicy $adminAcl,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function extensions(): array
    {
        $settingExtensions = $this->settingRegistry->extensionsWithDefinitions();
        $extensions = array_filter(
            $this->entityManager->getRepository(Extension::class)->findAll(),
            static fn (mixed $extension): bool => $extension instanceof Extension && 'system' !== $extension->extensionName(),
        );

        usort(
            $extensions,
            static fn (Extension $left, Extension $right): int => [
                $left->status()->value,
                $left->extensionName(),
            ] <=> [
                $right->status()->value,
                $right->extensionName(),
            ],
        );

        return [
            $this->systemRow(),
            ...array_map(
                fn (Extension $extension): array => $this->row($extension, $settingExtensions[$extension->extensionName()]['path'] ?? null),
                $extensions,
            ),
        ];
    }

    private function row(Extension $extension, ?string $settingsPath): array
    {
        $metadata = $extension->metadata();
        $label = $this->metadataString($metadata, 'display_name') ?? $extension->extensionName();

        return [
            'extension_name' => $extension->extensionName(),
            'label' => $label,
            'label_key' => null,
            'detail_path' => $this->detailPath($extension->extensionName()),
            'description' => $this->metadataString($metadata, 'description'),
            'description_key' => null,
            'author' => $this->metadataString($metadata, 'author'),
            'path' => $extension->path(),
            'immutable' => false,
            'status' => $extension->status()->value,
            'status_label_key' => 'admin.extensions.status.'.$extension->status()->value,
            'status_tone' => $this->statusTone($extension->status()),
            'actions' => $this->actions($extension),
            'scopes' => array_map(static fn (string $scope): array => [
                'value' => $scope,
                'label_key' => 'admin.extensions.scope.'.str_replace('-', '_', $scope),
            ], $extension->scopeValues()),
            'manifest_version' => $extension->manifestVersion(),
            'installed_version' => $extension->installedVersion(),
            'available_version' => $extension->availableVersion(),
            'settings_path' => $settingsPath,
        ];
    }

    private function systemRow(): array
    {
        $metadata = $this->systemExtensionMetadata->metadata();
        $version = $metadata['version'] ?? null;

        return [
            'extension_name' => 'system',
            'label' => $metadata['name'],
            'label_key' => null,
            'detail_path' => $this->detailPath('system'),
            'description' => $metadata['description'],
            'description_key' => null,
            'author' => $metadata['author'],
            'path' => '.',
            'immutable' => true,
            'status' => ExtensionStatus::Active->value,
            'status_label_key' => 'admin.extensions.status.active',
            'status_tone' => 'success',
            'actions' => [],
            'scopes' => array_map(static fn (string $scope): array => [
                'value' => $scope,
                'label_key' => 'admin.extensions.scope.'.str_replace('-', '_', $scope),
            ], $metadata['scopes']),
            'manifest_version' => is_string($version) && '' !== trim($version) ? $version : null,
            'installed_version' => null,
            'available_version' => null,
            'settings_path' => null,
        ];
    }

    private function detailPath(string $extensionName): string
    {
        return '/admin/extensions/'.rawurlencode($extensionName);
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
            ExtensionStatus::Inactive => [$this->action($extension, 'activate', 'primary', $state)],
            ExtensionStatus::Active => [$this->action($extension, 'deactivate', 'secondary', $state)],
            ExtensionStatus::Faulty => [$this->action($extension, 'reset-fault', 'secondary', $state)],
            ExtensionStatus::Removed => [],
        };

        $cleanupActions = ExtensionStatus::Removed === $extension->status()
            ? [$this->action($extension, 'purge', 'danger', $state)]
            : [];

        if (ExtensionStatus::Removed !== $extension->status()) {
            $cleanupActions[] = $this->action($extension, 'delete', 'danger', $state);
        }

        return [...$stateActions, ...$cleanupActions];
    }

    private function action(Extension $extension, string $action, string $variant, AdminPermissionState $state): array
    {
        return [
            'id' => $action,
            'label_key' => 'admin.extensions.lifecycle.'.str_replace('-', '_', $action).'.label',
            'path' => $this->detailPath($extension->extensionName()).'/'.$action,
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
