<?php

declare(strict_types=1);

namespace App\Api\Admin;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointHandlerInterface;
use App\Api\Http\ApiResponder;
use App\Api\Security\ApiAccessGuard;
use App\Core\Access\AccessLevel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class AdminDeferredApiHandler implements ApiEndpointHandlerInterface
{
    public function __construct(
        private ApiAccessGuard $accessGuard,
        private ApiResponder $responder,
        private AdminFeatureApiGuard $featureGuard,
    ) {
    }

    public function apiEndpointHandlerKey(): string
    {
        return AdminOperationalApiEndpointProvider::HANDLER_BACKUPS;
    }

    public function handle(Request $request, ApiEndpointDefinition $endpoint): Response
    {
        $denied = $this->accessGuard->denyUnlessAccessLevel($request, AccessLevel::ADMIN);
        if (null !== $denied) {
            return $denied;
        }

        if ($denied = $this->featureGuard->denyUnlessVisible($request, 'admin.backup_restore', 'listAdminBackups')) {
            return $denied;
        }

        return $this->responder->data([
            'type' => 'api_navigation',
            'id' => $endpoint->path(),
            'attributes' => [
                'path' => $endpoint->path(),
                'methods' => [],
                'children' => [],
                'status' => 'deferred',
            ],
        ], meta: ['child_count' => 0]);
    }
}
