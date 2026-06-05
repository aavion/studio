<?php

declare(strict_types=1);

namespace App\View\Injection;

use App\Content\Read\PublishedContentView;
use App\Core\Access\AccessActor;
use App\Core\Access\AccessRule;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageReporterInterface;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Throwable;

final readonly class DynamicViewInjectionRenderer
{
    public function __construct(
        private ViewInjectionRegistry $registry,
        private Environment $twig,
        private MessageReporterInterface $messageReporter,
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
            } catch (Throwable $error) {
                $this->reportRenderFailure($injection, $view, $request, $error);
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
        } catch (Throwable $error) {
            $this->reportRenderFailure($injection, $view, $request, $error);

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

    private function reportRenderFailure(
        DynamicViewInjection $injection,
        PublishedContentView $view,
        Request $request,
        Throwable $error,
    ): void
    {
        try {
            $this->messageReporter->report(
                Message::warning(
                    MessageCode::VIEW_DYNAMIC_INJECTION_RENDER_FAILED,
                    MessageKey::VIEW_DYNAMIC_INJECTION_RENDER_FAILED,
                    [
                        '%uid%' => $injection->uid(),
                        '%template%' => $injection->template(),
                    ],
                ),
                [
                    'component' => self::class,
                    'injection_uid' => $injection->uid(),
                    'injection_label' => $injection->label(),
                    'template' => $injection->template(),
                    'surface' => $injection->surface()->value,
                    'slot' => $injection->slot()->value,
                    'variant_slug' => $injection->variantSlug(),
                    'content_uid' => $view->content()->uid(),
                    'path' => $request->getPathInfo(),
                    'route' => $request->attributes->get('_route'),
                    'exception_class' => $error::class,
                ],
            );
        } catch (Throwable) {
            return;
        }
    }
}
