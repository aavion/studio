<?php

declare(strict_types=1);

namespace App\View\Twig;

use App\Core\Event\EventHookDescriptor;
use App\Core\Event\PublicEventHookRegistry;
use App\View\MarkdownRenderer;
use App\View\PackageMacroRegistry;
use App\View\ViewContextProvider;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class ViewTwigExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly ViewContextProvider $contextProvider,
        private readonly PackageMacroRegistry $macroRegistry,
        private readonly MarkdownRenderer $markdownRenderer,
        private readonly PublicEventHookRegistry $eventHookRegistry,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getGlobals(): array
    {
        return [
            'studio_view' => $this->contextProvider,
        ];
    }

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('studio_view_context', $this->contextProvider->context(...)),
            new TwigFunction('studio_macro_namespaces', $this->macroRegistry->namespaces(...)),
            new TwigFunction('studio_macro_template', $this->macroRegistry->template(...)),
            new TwigFunction('studio_event_hooks', $this->eventHooks(...)),
        ];
    }

    /**
     * @return list<TwigFilter>
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('studio_markdown', $this->markdownRenderer->render(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function eventHooks(): array
    {
        return array_map(
            static fn (EventHookDescriptor $hook): array => $hook->toArray(),
            $this->eventHookRegistry->hooks(),
        );
    }
}
