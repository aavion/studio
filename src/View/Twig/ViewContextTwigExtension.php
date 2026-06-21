<?php

declare(strict_types=1);

namespace App\View\Twig;

use App\Core\Event\EventHookDescriptor;
use App\Core\Event\PublicEventHookRegistry;
use App\View\MarkdownRenderer;
use App\View\ExtensionMacroRegistry;
use App\View\ViewContextProvider;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class ViewContextTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly ViewContextProvider $contextProvider,
        private readonly ExtensionMacroRegistry $macroRegistry,
        private readonly MarkdownRenderer $markdownRenderer,
        private readonly PublicEventHookRegistry $eventHookRegistry,
    ) {
    }

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('view_context', $this->contextProvider->context(...)),
            new TwigFunction('macro_namespaces', $this->macroRegistry->namespaces(...)),
            new TwigFunction('macro_template', $this->macroRegistry->template(...)),
            new TwigFunction('event_hooks', $this->eventHooks(...)),
        ];
    }

    /**
     * @return list<TwigFilter>
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('render_markdown', $this->markdownRenderer->render(...), ['is_safe' => ['html']]),
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
