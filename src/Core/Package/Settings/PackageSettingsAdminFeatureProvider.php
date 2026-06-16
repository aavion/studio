<?php

declare(strict_types=1);

namespace App\Core\Package\Settings;

use App\Core\AdminAcl\AdminFeatureDefinition;
use App\Core\AdminAcl\AdminFeatureProviderInterface;
use App\Core\AdminAcl\AdminPermissionState;

final readonly class PackageSettingsAdminFeatureProvider implements AdminFeatureProviderInterface
{
    public function __construct(private PackageSettingRegistry $registry)
    {
    }

    /**
     * @return list<AdminFeatureDefinition>
     */
    public function adminFeatures(): array
    {
        $features = [];
        $sortOrder = 1000;

        foreach ($this->registry->packagesWithDefinitions() as $packageName => $metadata) {
            $features[] = new AdminFeatureDefinition(
                'admin.settings.packages.'.$packageName,
                (string) $metadata['label'],
                'admin.acl.features.admin_settings_package.description',
                'admin.acl.categories.package_settings',
                defaultState: AdminPermissionState::Mutable,
                sortOrder: $sortOrder++,
            );
        }

        return $features;
    }
}
