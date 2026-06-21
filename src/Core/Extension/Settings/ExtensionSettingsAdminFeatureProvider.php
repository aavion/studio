<?php

declare(strict_types=1);

namespace App\Core\Extension\Settings;

use App\Core\AdminAcl\AdminFeatureDefinition;
use App\Core\AdminAcl\AdminFeatureProviderInterface;
use App\Core\AdminAcl\AdminPermissionState;

final readonly class ExtensionSettingsAdminFeatureProvider implements AdminFeatureProviderInterface
{
    public function __construct(private ExtensionSettingRegistry $registry)
    {
    }

    /**
     * @return list<AdminFeatureDefinition>
     */
    public function adminFeatures(): array
    {
        $features = [];
        $sortOrder = 1000;

        foreach ($this->registry->extensionsWithDefinitions() as $extensionName => $metadata) {
            $features[] = new AdminFeatureDefinition(
                'admin.settings.extensions.'.$extensionName,
                (string) $metadata['label'],
                'admin.acl.features.admin_settings_extension.description',
                'admin.acl.categories.extension_settings',
                defaultState: AdminPermissionState::Mutable,
                sortOrder: $sortOrder++,
            );
        }

        return $features;
    }
}
