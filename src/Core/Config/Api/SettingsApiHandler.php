<?php

declare(strict_types=1);

namespace App\Core\Config\Api;

use App\Api\ApiMessageCode;
use App\Api\ApiMessageKey;
use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointHandlerInterface;
use App\Api\Http\ApiResponder;
use App\Api\Security\ApiAccessGuard;
use App\Core\Access\AccessLevel;
use App\Core\Message\Message;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class SettingsApiHandler implements ApiEndpointHandlerInterface
{
    public function __construct(
        private SettingsApiReadModel $readModel,
        private ApiAccessGuard $accessGuard,
        private ApiResponder $responder,
    ) {
    }

    public function apiEndpointHandlerKey(): string
    {
        return SettingsApiEndpointProvider::HANDLER_SETTINGS_INDEX;
    }

    public function handle(Request $request, ApiEndpointDefinition $endpoint): Response
    {
        $denied = $this->accessGuard->denyUnlessAccessLevel($request, AccessLevel::ADMIN);
        if (null !== $denied) {
            return $denied;
        }

        $section = $this->sectionFromPath($request->getPathInfo());
        $settings = null === $section
            ? $this->readModel->sections()
            : $this->readModel->settings($section);
        if (null !== $section && [] === $settings) {
            return $this->responder->error(
                Message::warning(ApiMessageCode::API_ENDPOINT_NOT_FOUND, ApiMessageKey::API_ENDPOINT_NOT_FOUND, context: [
                    'path' => $request->getPathInfo(),
                    'section' => $section,
                ]),
                Response::HTTP_NOT_FOUND,
                $request,
            );
        }

        return $this->responder->data($settings, meta: [
            'count' => count($settings),
            'section' => $section,
        ]);
    }

    private function sectionFromPath(string $path): ?string
    {
        $prefix = '/api/v1/admin/settings/';
        if (!str_starts_with($path, $prefix)) {
            return null;
        }

        $section = rawurldecode(substr($path, strlen($prefix)));

        return '' === $section ? null : $section;
    }
}
