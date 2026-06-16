<?php

declare(strict_types=1);

namespace App\Backend;

use App\Core\Access\AccessLevel;

final readonly class CoreBackendViewProvider implements BackendViewProviderInterface
{
    /**
     * @return list<BackendViewDefinition>
     */
    public function backendViews(): array
    {
        return [
            new BackendViewDefinition(
                'backend-admin-dashboard',
                BackendArea::Admin,
                '',
                'admin.navigation.dashboard',
                '@backend/admin/index.html.twig',
                10,
                minimumAccessLevel: AccessLevel::ADMIN,
            ),
            new BackendViewDefinition(
                'backend-admin-packages',
                BackendArea::Admin,
                'packages',
                'admin.navigation.packages',
                '@backend/admin/packages.html.twig',
                20,
                minimumAccessLevel: AccessLevel::ADMIN,
                context: [
                    'access_feature' => 'admin.packages',
                ],
            ),
            new BackendViewDefinition(
                'backend-admin-themes',
                BackendArea::Admin,
                'themes',
                'admin.navigation.themes',
                '@backend/admin/themes.html.twig',
                30,
                minimumAccessLevel: AccessLevel::ADMIN,
                context: [
                    'access_feature' => 'admin.packages',
                ],
            ),
            new BackendViewDefinition(
                'backend-admin-users',
                BackendArea::Admin,
                'users',
                'admin.navigation.users',
                '@backend/admin/users/index.html.twig',
                40,
                minimumAccessLevel: AccessLevel::ADMIN,
                context: [
                    'title_key' => 'admin.users.title',
                    'foundation_title_key' => 'admin.users.foundation_title',
                    'foundation_text_key' => 'admin.users.foundation_text',
                    'access_feature' => 'admin.users',
                ],
            ),
            new BackendViewDefinition(
                'backend-admin-user-groups',
                BackendArea::Admin,
                'users/groups',
                'admin.navigation.user_groups',
                '@backend/admin/users/groups.html.twig',
                10,
                parentUid: 'backend-admin-users',
                minimumAccessLevel: AccessLevel::ADMIN,
                context: [
                    'access_feature' => 'admin.users.acl',
                ],
            ),
            new BackendViewDefinition(
                'backend-admin-user-reviews',
                BackendArea::Admin,
                'users/reviews',
                'admin.navigation.user_reviews',
                '@backend/admin/users/reviews.html.twig',
                20,
                parentUid: 'backend-admin-users',
                minimumAccessLevel: AccessLevel::ADMIN,
                context: [
                    'access_feature' => 'admin.users.review',
                ],
            ),
            new BackendViewDefinition(
                'backend-admin-scheduler',
                BackendArea::Admin,
                'scheduler',
                'admin.navigation.scheduler',
                '@backend/admin/scheduler/index.html.twig',
                50,
                minimumAccessLevel: AccessLevel::ADMIN,
                context: [
                    'title_key' => 'admin.scheduler.title',
                    'foundation_title_key' => 'admin.scheduler.foundation_title',
                    'foundation_text_key' => 'admin.scheduler.foundation_text',
                    'access_feature' => 'admin.scheduler',
                ],
            ),
            new BackendViewDefinition(
                'backend-admin-backups',
                BackendArea::Admin,
                'backups',
                'admin.navigation.backups',
                '@backend/admin/section.html.twig',
                60,
                minimumAccessLevel: AccessLevel::ADMIN,
                context: [
                    'title_key' => 'admin.backups.title',
                    'foundation_title_key' => 'admin.backups.foundation_title',
                    'foundation_text_key' => 'admin.backups.foundation_text',
                    'access_feature' => 'admin.backup_restore',
                ],
            ),
            new BackendViewDefinition(
                'backend-admin-operations',
                BackendArea::Admin,
                'operations',
                'admin.navigation.operations',
                '@backend/admin/operations.html.twig',
                70,
                minimumAccessLevel: AccessLevel::ADMIN,
                context: [
                    'access_feature' => 'admin.operations',
                ],
            ),
            new BackendViewDefinition(
                'backend-admin-logs',
                BackendArea::Admin,
                'logs',
                'admin.navigation.logs',
                '@backend/admin/logs.html.twig',
                800,
                minimumAccessLevel: AccessLevel::ADMIN,
                context: [
                    'access_feature' => 'admin.logs',
                ],
            ),
            new BackendViewDefinition(
                'backend-admin-statistics',
                BackendArea::Admin,
                'statistics',
                'admin.navigation.statistics',
                '@backend/admin/statistics.html.twig',
                810,
                minimumAccessLevel: AccessLevel::ADMIN,
                context: [
                    'access_feature' => 'admin.settings.statistics',
                ],
            ),
            new BackendViewDefinition(
                'backend-admin-settings',
                BackendArea::Admin,
                'settings',
                'admin.navigation.settings',
                '@backend/admin/settings/index.html.twig',
                900,
                minimumAccessLevel: AccessLevel::ADMIN,
            ),
            new BackendViewDefinition(
                'backend-editor-dashboard',
                BackendArea::Editor,
                '',
                'editor.navigation.dashboard',
                '@backend/editor/index.html.twig',
                10,
                minimumAccessLevel: AccessLevel::AUTHOR,
            ),
        ];
    }
}
