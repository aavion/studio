<?php

declare(strict_types=1);

namespace App\Core\Extension\Settings;

use App\Backend\BackendArea;
use App\Backend\BackendViewDefinition;
use App\Backend\BackendViewProviderInterface;
use App\Core\Access\AccessLevel;

final readonly class ExtensionSettingsBackendViewProvider implements BackendViewProviderInterface
{
    public function __construct(private ExtensionSettingRegistry $registry)
    {
    }

    /**
     * @return list<BackendViewDefinition>
     */
    public function backendViews(): array
    {
        $views = [];
        $sortOrder = 100;

        foreach ($this->registry->extensionsWithDefinitions() as $extensionName => $metadata) {
            $views[] = new BackendViewDefinition(
                'backend-admin-extension-settings-'.hash('xxh3', $extensionName),
                BackendArea::Admin,
                'settings/extensions/'.$extensionName,
                $metadata['label'],
                '@backend/admin/settings/extension.html.twig',
                $sortOrder,
                parentUid: 'backend-admin-settings-extensions',
                minimumAccessLevel: AccessLevel::ADMIN,
                context: [
                    'extension_name' => $extensionName,
                    'description' => $metadata['description'],
                    'access_feature' => 'admin.settings.extensions.'.$extensionName,
                ],
            );
            ++$sortOrder;
        }

        return $views;
    }
}
