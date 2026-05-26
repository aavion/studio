<?php

declare(strict_types=1);

namespace App\View\Injection;

use App\Navigation\Event\NavigationBuilderEvent;
use App\Navigation\NavigationItem;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class StaticViewInjectionNavigationSubscriber implements EventSubscriberInterface
{
    public function __construct(private ViewInjectionRegistry $registry)
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
        if ('main' !== $event->identifier()) {
            return;
        }

        foreach ($this->registry->staticInjections(ViewSurface::Public, menuOnly: true) as $injection) {
            $event->addItem(new NavigationItem(
                $injection->uid(),
                $injection->label(),
                'route',
                $this->routeName($injection),
                $this->parentUid($injection),
                $injection->sortOrder(),
                [
                    'min_access_level' => $injection->accessLevel(),
                    'access_groups' => $injection->accessGroups(),
                    'route_parameters' => $this->routeParameters($injection),
                    'link_attributes' => $injection->linkAttributes(),
                ],
            ));
        }
    }

    private function routeName(StaticViewInjection $injection): string
    {
        return match ($injection->surface()) {
            ViewSurface::Public => '/' === $injection->routePath() ? 'content_home' : 'content_show',
            ViewSurface::Admin => '' === $injection->pathSlug() ? 'backend_admin_index' : 'backend_admin_route',
            ViewSurface::Editor => '' === $injection->pathSlug() ? 'backend_editor_index' : 'backend_editor_route',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function routeParameters(StaticViewInjection $injection): array
    {
        if (ViewSurface::Public === $injection->surface()) {
            if ('/' === $injection->routePath()) {
                return [];
            }

            return ['path' => ltrim($injection->routePath(), '/')];
        }

        return '' === $injection->pathSlug() ? [] : ['path' => $injection->pathSlug()];
    }

    private function parentUid(StaticViewInjection $injection): ?string
    {
        $parentSlug = $injection->parentSlug();

        if (null === $parentSlug) {
            return null;
        }

        foreach ($this->registry->staticInjections($injection->surface(), menuOnly: true) as $candidate) {
            if ($candidate->pathSlug() === $parentSlug) {
                return $candidate->uid();
            }
        }

        return null;
    }
}
