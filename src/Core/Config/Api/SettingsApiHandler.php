<?php

declare(strict_types=1);

namespace App\Core\Config\Api;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointHandlerInterface;
use App\Api\Http\ApiResponder;
use App\Api\Security\ApiAccessGuard;
use App\Core\Access\AccessLevel;
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

        $settings = $this->readModel->settings();

        return $this->responder->data($settings, meta: [
            'count' => count($settings),
        ]);
    }
}
