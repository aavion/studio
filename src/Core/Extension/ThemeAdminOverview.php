<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Access\AccessActor;
use App\Core\AdminAcl\AdminFeatureAccessPolicy;
use App\Core\AdminAcl\AdminPermissionState;
use App\Entity\Extension;
use App\Entity\UserAccount;
use App\View\SystemExtensionMetadataProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

final readonly class ThemeAdminOverview
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SystemExtensionMetadataProvider $systemExtensionMetadata,
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
            $this->section('frontend', ExtensionScope::FrontendTheme, 'templates/frontend'),
            $this->section('backend', ExtensionScope::BackendTheme, 'templates/backend'),
        ];
    }

    private function section(string $key, ExtensionScope $scope, string $systemPath): array
    {
        $state = $this->extensionState();
        $extensions = $this->extensions($scope);
        $activeExtension = $this->activeExtension($extensions);
        $themes = [
            $this->systemThemeRow($scope, $systemPath, null === $activeExtension, $activeExtension, $state),
            ...array_map(fn (Extension $extension): array => $this->extensionRow($extension, $state), $extensions),
        ];

        return [
            'key' => $key,
            'title_key' => 'admin.themes.sections.'.$key.'.title',
            'text_key' => 'admin.themes.sections.'.$key.'.text',
            'themes' => $themes,
        ];
    }

    /**
     * @return list<Extension>
     */
    private function extensions(ExtensionScope $scope): array
    {
        $extensions = array_filter(
            $this->entityManager->getRepository(Extension::class)->findAll(),
            static fn (mixed $extension): bool => $extension instanceof Extension
                && 'system' !== $extension->extensionName()
                && $extension->hasScope($scope)
                && ExtensionStatus::Removed !== $extension->status(),
        );

        usort(
            $extensions,
            static fn (Extension $left, Extension $right): int => [
                self::statusSort($left->status()),
                $left->extensionName(),
            ] <=> [
                self::statusSort($right->status()),
                $right->extensionName(),
            ],
        );

        return array_values($extensions);
    }

    private function extensionRow(Extension $extension, AdminPermissionState $state): array
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
            'type_label_key' => 'admin.themes.type.extension',
            'type_tone' => 'neutral',
            'version' => $extension->installedVersion() ?? $extension->manifestVersion(),
            'quick_action' => $state->isVisible() ? $this->quickAction($extension, $state) : null,
        ];
    }

    private function systemThemeRow(ExtensionScope $scope, string $path, bool $active, ?Extension $activeExtension, AdminPermissionState $state): array
    {
        $metadata = $this->systemExtensionMetadata->metadata();
        $version = $metadata['version'] ?? null;
        $status = $active ? ExtensionStatus::Active : ExtensionStatus::Inactive;

        return [
            'extension_name' => 'system',
            'label' => $metadata['name'],
            'label_key' => null,
            'detail_path' => $this->detailPath('system'),
            'description' => $metadata['description'],
            'description_key' => null,
            'author' => $metadata['author'],
            'path' => $path,
            'immutable' => true,
            'status' => $status->value,
            'status_label_key' => 'admin.extensions.status.'.$status->value,
            'status_tone' => $this->statusTone($status),
            'type_label_key' => 'admin.themes.type.system',
            'type_tone' => 'info',
            'version' => is_string($version) && '' !== trim($version) ? $version : null,
            'scope' => $scope->value,
            'quick_action' => $active
                ? $this->disabledQuickAction('admin.themes.quick.active', 'secondary')
                : (null === $activeExtension || !$state->isVisible() ? null : $this->linkedQuickAction('admin.themes.quick.use', $this->detailPath($activeExtension->extensionName()).'/deactivate', 'primary', !$state->isMutable())),
        ];
    }

    private function detailPath(string $extensionName): string
    {
        return '/admin/extensions/'.rawurlencode($extensionName);
    }

    /**
     * @param list<Extension> $extensions
     */
    private function activeExtension(array $extensions): ?Extension
    {
        foreach ($extensions as $extension) {
            if (ExtensionStatus::Active === $extension->status()) {
                return $extension;
            }
        }

        return null;
    }

    private function quickAction(Extension $extension, AdminPermissionState $state): ?array
    {
        return match ($extension->status()) {
            ExtensionStatus::Active => $this->disabledQuickAction('admin.themes.quick.active', 'secondary'),
            ExtensionStatus::Inactive => $this->linkedQuickAction('admin.themes.quick.use', $this->detailPath($extension->extensionName()).'/activate', 'primary', !$state->isMutable()),
            ExtensionStatus::Faulty => $this->linkedQuickAction('admin.themes.quick.repair', $this->detailPath($extension->extensionName()).'/reset-fault', 'secondary', !$state->isMutable()),
            ExtensionStatus::Removed => null,
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

    private function statusTone(ExtensionStatus $status): string
    {
        return match ($status) {
            ExtensionStatus::Active => 'success',
            ExtensionStatus::Inactive => 'neutral',
            ExtensionStatus::Removed => 'warning',
            ExtensionStatus::Faulty => 'error',
        };
    }

    private static function statusSort(ExtensionStatus $status): int
    {
        return match ($status) {
            ExtensionStatus::Active => 0,
            ExtensionStatus::Inactive => 1,
            ExtensionStatus::Faulty => 2,
            ExtensionStatus::Removed => 3,
        };
    }

    private function extensionState(): AdminPermissionState
    {
        return $this->adminAcl->state('admin.extensions', $this->actor());
    }

    private function actor(): AccessActor
    {
        $user = $this->security->getUser();

        return $user instanceof UserAccount ? AccessActor::fromUserAccount($user) : AccessActor::anonymous();
    }
}
