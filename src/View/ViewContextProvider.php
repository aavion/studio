<?php

declare(strict_types=1);

namespace App\View;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class ViewContextProvider
{
    public function __construct(
        private SystemPackageMetadataProvider $systemPackage,
        private PackageMacroRegistry $macroRegistry,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        $event = new ViewContextEvent([
            'system_package' => $this->systemPackage->metadata(),
            'active_frontend_theme' => null,
            'active_backend_theme' => null,
            'macro_namespaces' => $this->macroRegistry->namespaces(),
            'template_areas' => [
                'frontend' => ['content', 'error-pages', 'user'],
                'backend' => ['admin', 'editor', 'setup', 'operations'],
            ],
        ]);

        $this->eventDispatcher->dispatch($event, ViewContextEvent::NAME);

        return $event->context();
    }
}
