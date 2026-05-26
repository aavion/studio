<?php

declare(strict_types=1);

namespace App\View\Injection;

use App\Content\Read\PublishedContentView;
use App\Core\Access\AccessActor;
use App\Core\Access\AccessRule;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Throwable;

final readonly class DynamicViewInjectionRenderer
{
    public function __construct(
        private ViewInjectionRegistry $registry,
        private Environment $twig,
    ) {
    }

    /**
     * @return list<array{uid: string, label: string|null, content: string}>
     */
    public function renderSlot(
        PublishedContentView $view,
        Request $request,
        DynamicViewInjectionSlot $slot,
        AccessActor $actor,
    ): array {
        $rendered = [];

        foreach ($this->registry->dynamicInjections(ViewSurface::Public, $slot, $view) as $injection) {
            if (!$this->allows($injection, $actor)) {
                continue;
            }

            try {
                $rendered[] = [
                    'uid' => $injection->uid(),
                    'label' => $injection->label(),
                    'content' => $this->render($injection, $view, $request),
                ];
            } catch (Throwable) {
                continue;
            }
        }

        return $rendered;
    }

    public function renderRoute(
        DynamicViewInjection $injection,
        PublishedContentView $view,
        Request $request,
        AccessActor $actor,
    ): ?string {
        if (!$this->allows($injection, $actor)) {
            return null;
        }

        try {
            return $this->render($injection, $view, $request);
        } catch (Throwable) {
            return null;
        }
    }

    private function render(DynamicViewInjection $injection, PublishedContentView $view, Request $request): string
    {
        return $this->twig->render($injection->template(), [
            'content_view' => $view,
            'request' => $request,
            'injection' => $injection,
            ...$injection->templateContext(),
        ]);
    }

    private function allows(DynamicViewInjection $injection, AccessActor $actor): bool
    {
        if (null === $injection->accessLevel() && [] === $injection->accessGroups()) {
            return true;
        }

        return AccessRule::from($injection->accessLevel(), $injection->accessGroups())->allows($actor);
    }
}
