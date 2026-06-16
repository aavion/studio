<?php

declare(strict_types=1);

namespace App\Core\Package\Settings;

use App\Backend\BackendArea;
use App\Backend\BackendViewDefinition;
use App\Backend\BackendViewProviderInterface;
use App\Core\Access\AccessLevel;

final readonly class PackageSettingsBackendViewProvider implements BackendViewProviderInterface
{
    public function __construct(private PackageSettingRegistry $registry)
    {
    }

    /**
     * @return list<BackendViewDefinition>
     */
    public function backendViews(): array
    {
        $views = [];
        $sortOrder = 100;

        foreach ($this->registry->packagesWithDefinitions() as $packageName => $metadata) {
            $views[] = new BackendViewDefinition(
                'backend-admin-package-settings-'.hash('xxh3', $packageName),
                BackendArea::Admin,
                'settings/packages/'.$packageName,
                $metadata['label'],
                '@backend/admin/settings/package.html.twig',
                $sortOrder,
                parentUid: 'backend-admin-settings-packages',
                minimumAccessLevel: AccessLevel::ADMIN,
                context: [
                    'package_name' => $packageName,
                    'description' => $metadata['description'],
                    'access_feature' => 'admin.settings.packages.'.$packageName,
                ],
            );
            ++$sortOrder;
        }

        return $views;
    }
}
