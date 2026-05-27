<?php

declare(strict_types=1);

namespace App\Backend;

use App\Navigation\Event\NavigationBuilderEvent;
use App\Navigation\NavigationItem;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class BackendNavigationSubscriber implements EventSubscriberInterface
{
    public function __construct(private BackendViewRegistry $viewRegistry)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            NavigationBuilderEvent::class => 'onNavigationBuilder',
        ];
    }

    public function onNavigationBuilder(NavigationBuilderEvent $event): void
    {
        $area = match ($event->identifier()) {
            BackendArea::Admin->navigationIdentifier() => BackendArea::Admin,
            BackendArea::Editor->navigationIdentifier() => BackendArea::Editor,
            default => null,
        };

        if (!$area instanceof BackendArea) {
            return;
        }

        foreach ($this->viewRegistry->views($area) as $view) {
            $event->addItem(new NavigationItem(
                $view->uid(),
                $view->label(),
                'route',
                $view->routeName(),
                $view->parentUid(),
                $view->sortOrder(),
                [
                    'min_access_level' => $view->minimumAccessLevel(),
                    'access_groups' => $view->accessGroups(),
                    'route_parameters' => $view->routeParameters(),
                    'link_attributes' => $view->linkAttributes(),
                ],
            ));
        }
    }
}
