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
                minimumAccessLevel: 8,
            ),
            new BackendViewDefinition(
                'backend-admin-packages',
                BackendArea::Admin,
                'packages',
                'admin.navigation.packages',
                '@backend/admin/packages.html.twig',
                20,
                minimumAccessLevel: 8,
            ),
            new BackendViewDefinition(
                'backend-editor-dashboard',
                BackendArea::Editor,
                '',
                'editor.navigation.dashboard',
                '@backend/editor/index.html.twig',
                10,
                minimumAccessLevel: AccessLevel::EDITOR,
            ),
        ];
    }
}
