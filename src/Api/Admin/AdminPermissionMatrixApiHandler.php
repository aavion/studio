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

final readonly class AdminPermissionMatrixApiHandler implements ApiEndpointHandlerInterface
{
    public function __construct(
        private AdminPermissionMatrixReadModel $readModel,
        private ApiAccessGuard $accessGuard,
        private ApiResponder $responder,
    ) {
    }

    public function apiEndpointHandlerKey(): string
    {
        return AdminApiEndpointProvider::HANDLER_ADMIN_PERMISSIONS;
    }

    public function handle(Request $request, ApiEndpointDefinition $endpoint): Response
    {
        $denied = $this->accessGuard->denyUnlessAccessLevel($request, AccessLevel::ADMIN);
        if (null !== $denied) {
            return $denied;
        }

        $resources = $this->readModel->resources();

        return $this->responder->data($resources, meta: [
            'count' => count($resources),
            'notes' => [
                'read_only keys may call safe methods only',
                'read_write keys still execute in the owning user context',
                'domain handlers remain authoritative for fine-grained ACL checks',
            ],
        ]);
    }
}
