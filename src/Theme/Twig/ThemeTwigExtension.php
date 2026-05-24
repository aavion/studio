<?php

declare(strict_types=1);

namespace App\Theme\Twig;

use App\Theme\MarkdownRenderer;
use App\Theme\ThemeMacroRegistry;
use App\Theme\ThemeViewContextProvider;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class ThemeTwigExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly ThemeViewContextProvider $contextProvider,
        private readonly ThemeMacroRegistry $macroRegistry,
        private readonly MarkdownRenderer $markdownRenderer,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getGlobals(): array
    {
        return [
            'studio_theme' => $this->contextProvider,
        ];
    }

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('studio_theme_context', $this->contextProvider->context(...)),
            new TwigFunction('studio_macro_namespaces', $this->macroRegistry->namespaces(...)),
            new TwigFunction('studio_macro_template', $this->macroRegistry->template(...)),
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
}
