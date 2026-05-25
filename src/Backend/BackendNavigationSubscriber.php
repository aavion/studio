<?php

declare(strict_types=1);

namespace App\Backend;

use App\Core\Access\AccessLevel;
use App\Navigation\Event\NavigationBuilderEvent;
use App\Navigation\NavigationItem;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class BackendNavigationSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            NavigationBuilderEvent::class => 'onNavigationBuilder',
        ];
    }

    public function onNavigationBuilder(NavigationBuilderEvent $event): void
    {
        match ($event->identifier()) {
            'backend.admin' => $this->addAdminItems($event),
            'backend.editor' => $this->addEditorItems($event),
            default => null,
        };
    }

    private function addAdminItems(NavigationBuilderEvent $event): void
    {
        $event->addItem(new NavigationItem(
            'backend-admin-dashboard',
            'admin.navigation.dashboard',
            'route',
            'backend_admin_index',
            sortOrder: 10,
            metadata: ['min_access_level' => 8],
        ));
    }

    private function addEditorItems(NavigationBuilderEvent $event): void
    {
        $event->addItem(new NavigationItem(
            'backend-editor-dashboard',
            'editor.navigation.dashboard',
            'route',
            'backend_editor_index',
            sortOrder: 10,
            metadata: ['min_access_level' => AccessLevel::EDITOR],
        ));
    }
}
