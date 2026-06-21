<?php

declare(strict_types=1);

namespace App\Backend;

use App\Core\Access\AccessLevel;

final readonly class CoreAdminSettingsBackendViewProvider implements BackendViewProviderInterface
{
    /**
     * @return list<BackendViewDefinition>
     */
    public function backendViews(): array
    {
        return [
            $this->settingsSection('general', 'admin.navigation.general_settings', 10),
            $this->settingsSection('dashboard', 'admin.navigation.dashboard_settings', 20),
            $this->settingsSection('users', 'admin.navigation.user_settings', 30),
            $this->settingsSection('mail', 'admin.navigation.mail_settings', 40),
            $this->settingsSection('security', 'admin.navigation.security_settings', 50, 'admin.settings.security'),
            $this->settingsSection('statistics', 'admin.navigation.statistics_settings', 55, 'admin.settings.statistics'),
            $this->settingsSection('logging', 'admin.navigation.logging_settings', 57, 'admin.settings.logging'),
            $this->settingsSection('api', 'admin.navigation.api_settings', 60, 'admin.settings.api'),
            new BackendViewDefinition(
                'backend-admin-settings-acl',
                BackendArea::Admin,
                'settings/acl',
                'admin.navigation.acl_settings',
                '@backend/admin/settings/acl.html.twig',
                65,
                parentUid: 'backend-admin-settings',
                minimumAccessLevel: AccessLevel::OWNER,
            ),
            new BackendViewDefinition(
                'backend-admin-settings-extensions',
                BackendArea::Admin,
                'settings/extensions',
                'admin.navigation.extension_settings',
                '@backend/admin/settings/extensions.html.twig',
                70,
                parentUid: 'backend-admin-settings',
                minimumAccessLevel: AccessLevel::ADMIN,
                context: [
                    'access_feature' => 'admin.settings.extensions',
                ],
            ),
            $this->settingsSection('scheduler', 'admin.navigation.scheduler_settings', 70, 'admin.settings.scheduler'),
            new BackendViewDefinition(
                'backend-admin-settings-system-info',
                BackendArea::Admin,
                'settings/system-info',
                'admin.navigation.system_info',
                '@backend/admin/settings/system-info.html.twig',
                75,
                parentUid: 'backend-admin-settings',
                minimumAccessLevel: AccessLevel::ADMIN,
            ),
        ];
    }

    private function settingsSection(string $section, string $label, int $sortOrder, ?string $feature = null): BackendViewDefinition
    {
        $context = [
            'title_key' => 'admin.settings.'.$section.'.title',
            'foundation_title_key' => 'admin.settings.'.$section.'.foundation_title',
            'foundation_text_key' => 'admin.settings.'.$section.'.foundation_text',
            'settings_section' => $section,
        ];

        if (null !== $feature) {
            $context['access_feature'] = $feature;
        }

        return new BackendViewDefinition(
            'backend-admin-settings-'.$section,
            BackendArea::Admin,
            'settings/'.$section,
            $label,
            '@backend/admin/settings/section.html.twig',
            $sortOrder,
            parentUid: 'backend-admin-settings',
            minimumAccessLevel: AccessLevel::ADMIN,
            context: $context,
        );
    }
}
