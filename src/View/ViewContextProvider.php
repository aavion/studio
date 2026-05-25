<?php

declare(strict_types=1);

namespace App\View;

use App\Core\Event\PublicEventDispatcher;

final readonly class ViewContextProvider
{
    public function __construct(
        private SystemPackageMetadataProvider $systemPackage,
        private PackageMacroRegistry $macroRegistry,
        private PublicEventDispatcher $eventDispatcher,
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

        $result = $this->eventDispatcher->dispatch($event, [
            'operation' => 'view_context',
        ]);
        if (!$result->isSuccess()) {
            $event->set('hook_issues', array_map(
                static fn ($issue): array => $issue->toArray(),
                $result->issues(),
            ));
        }

        return $event->context();
    }
}
