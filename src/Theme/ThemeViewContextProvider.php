<?php

declare(strict_types=1);

namespace App\Theme;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class ThemeViewContextProvider
{
    public function __construct(
        private SystemThemeMetadataProvider $systemTheme,
        private ThemeMacroRegistry $macroRegistry,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        $event = new ThemeViewContextEvent([
            'system_theme' => $this->systemTheme->metadata(),
            'active_theme' => null,
            'macro_namespaces' => $this->macroRegistry->namespaces(),
            'template_areas' => [
                'public' => ['content'],
                'system' => ['system', 'admin', 'editor', 'setup'],
            ],
        ]);

        $this->eventDispatcher->dispatch($event, ThemeViewContextEvent::NAME);

        return $event->context();
    }
}
