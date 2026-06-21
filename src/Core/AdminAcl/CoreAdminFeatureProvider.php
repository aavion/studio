<?php

declare(strict_types=1);

namespace App\Core\AdminAcl;

final readonly class CoreAdminFeatureProvider implements AdminFeatureProviderInterface
{
    /**
     * @return list<AdminFeatureDefinition>
     */
    public function adminFeatures(): array
    {
        return [
            new AdminFeatureDefinition(
                'admin.settings.security',
                'admin.acl.features.admin_settings_security.label',
                'admin.acl.features.admin_settings_security.description',
                'admin.acl.categories.settings',
                configurable: false,
                sortOrder: 10,
            ),
            new AdminFeatureDefinition(
                'admin.settings.logging',
                'admin.acl.features.admin_settings_logging.label',
                'admin.acl.features.admin_settings_logging.description',
                'admin.acl.categories.settings',
                sortOrder: 20,
            ),
            new AdminFeatureDefinition(
                'admin.settings.statistics',
                'admin.acl.features.admin_settings_statistics.label',
                'admin.acl.features.admin_settings_statistics.description',
                'admin.acl.categories.settings',
                sortOrder: 30,
            ),
            new AdminFeatureDefinition(
                'admin.settings.statistics.geoip',
                'admin.acl.features.admin_settings_statistics_geoip.label',
                'admin.acl.features.admin_settings_statistics_geoip.description',
                'admin.acl.categories.settings',
                sortOrder: 40,
            ),
            new AdminFeatureDefinition(
                'admin.settings.api',
                'admin.acl.features.admin_settings_api.label',
                'admin.acl.features.admin_settings_api.description',
                'admin.acl.categories.settings',
                sortOrder: 50,
            ),
            new AdminFeatureDefinition(
                'admin.settings.scheduler',
                'admin.acl.features.admin_settings_scheduler.label',
                'admin.acl.features.admin_settings_scheduler.description',
                'admin.acl.categories.settings',
                sortOrder: 60,
            ),
            new AdminFeatureDefinition(
                'admin.settings.extensions',
                'admin.acl.features.admin_settings_extensions.label',
                'admin.acl.features.admin_settings_extensions.description',
                'admin.acl.categories.settings',
                sortOrder: 70,
            ),
            new AdminFeatureDefinition(
                'admin.logs',
                'admin.acl.features.admin_logs.label',
                'admin.acl.features.admin_logs.description',
                'admin.acl.categories.diagnostics',
                sortOrder: 100,
            ),
            new AdminFeatureDefinition(
                'admin.extensions',
                'admin.acl.features.admin_extensions.label',
                'admin.acl.features.admin_extensions.description',
                'admin.acl.categories.extensions',
                sortOrder: 110,
            ),
            new AdminFeatureDefinition(
                'admin.extensions.self_update',
                'admin.acl.features.admin_extensions_self_update.label',
                'admin.acl.features.admin_extensions_self_update.description',
                'admin.acl.categories.extensions',
                configurable: false,
                sortOrder: 120,
            ),
            new AdminFeatureDefinition(
                'admin.backup_restore',
                'admin.acl.features.admin_backup_restore.label',
                'admin.acl.features.admin_backup_restore.description',
                'admin.acl.categories.system',
                defaultState: AdminPermissionState::Visible,
                configurable: false,
                sortOrder: 130,
            ),
            new AdminFeatureDefinition(
                'admin.operations',
                'admin.acl.features.admin_operations.label',
                'admin.acl.features.admin_operations.description',
                'admin.acl.categories.operations',
                sortOrder: 140,
            ),
            new AdminFeatureDefinition(
                'admin.actions.maintenance',
                'admin.acl.features.admin_actions_maintenance.label',
                'admin.acl.features.admin_actions_maintenance.description',
                'admin.acl.categories.operations',
                sortOrder: 150,
            ),
            new AdminFeatureDefinition(
                'admin.scheduler',
                'admin.acl.features.admin_scheduler.label',
                'admin.acl.features.admin_scheduler.description',
                'admin.acl.categories.operations',
                sortOrder: 160,
            ),
            new AdminFeatureDefinition(
                'admin.users',
                'admin.acl.features.admin_users.label',
                'admin.acl.features.admin_users.description',
                'admin.acl.categories.users',
                sortOrder: 170,
            ),
            new AdminFeatureDefinition(
                'admin.users.acl',
                'admin.acl.features.admin_users_acl.label',
                'admin.acl.features.admin_users_acl.description',
                'admin.acl.categories.users',
                sortOrder: 180,
            ),
            new AdminFeatureDefinition(
                'admin.users.review',
                'admin.acl.features.admin_users_review.label',
                'admin.acl.features.admin_users_review.description',
                'admin.acl.categories.users',
                sortOrder: 190,
            ),
            new AdminFeatureDefinition(
                'admin.support',
                'admin.acl.features.admin_support.label',
                'admin.acl.features.admin_support.description',
                'admin.acl.categories.system',
                configurable: false,
                sortOrder: 200,
            ),
        ];
    }
}
