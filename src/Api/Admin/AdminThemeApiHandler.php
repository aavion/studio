<?php

declare(strict_types=1);

namespace App\Api\Admin;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointHandlerInterface;
use App\Api\Http\ApiResponder;
use App\Api\Security\ApiAccessGuard;
use App\Core\Access\AccessLevel;
use App\Core\Extension\ThemeAdminOverview;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class AdminThemeApiHandler implements ApiEndpointHandlerInterface
{
    public function __construct(
        private ThemeAdminOverview $themes,
        private ApiAccessGuard $accessGuard,
        private ApiResponder $responder,
        private AdminFeatureApiGuard $featureGuard,
    ) {
    }

    public function apiEndpointHandlerKey(): string
    {
        return AdminOperationalApiEndpointProvider::HANDLER_THEMES;
    }

    public function handle(Request $request, ApiEndpointDefinition $endpoint): Response
    {
        $denied = $this->accessGuard->denyUnlessAccessLevel($request, AccessLevel::ADMIN);
        if (null !== $denied) {
            return $denied;
        }

        if ($denied = $this->featureGuard->denyUnlessVisible($request, 'admin.extensions', 'listAdminThemes')) {
            return $denied;
        }

        $sections = array_map($this->section(...), $this->themes->sections());

        return $this->responder->data($sections, meta: ['count' => count($sections)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function section(array $section): array
    {
        return [
            'type' => 'theme_section',
            'id' => (string) ($section['key'] ?? ''),
            'attributes' => [
                'title_key' => $section['title_key'] ?? null,
                'text_key' => $section['text_key'] ?? null,
                'themes' => array_map($this->theme(...), $section['themes'] ?? []),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function theme(array $theme): array
    {
        unset($theme['detail_path'], $theme['quick_action']);

        return $theme;
    }
}
