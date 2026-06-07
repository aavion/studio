<?php

declare(strict_types=1);

namespace App\Api\Admin;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointHandlerInterface;
use App\Api\Endpoint\ApiEndpointRegistry;
use App\Api\Http\ApiResponder;
use App\Api\Security\ApiAccessGuard;
use App\Core\Access\AccessLevel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class AdminApiIndexHandler implements ApiEndpointHandlerInterface
{
    public function __construct(
        private ApiEndpointRegistry $endpoints,
        private ApiAccessGuard $accessGuard,
        private ApiResponder $responder,
    ) {
    }

    public function apiEndpointHandlerKey(): string
    {
        return AdminApiEndpointProvider::HANDLER_ADMIN_INDEX;
    }

    public function handle(Request $request, ApiEndpointDefinition $endpoint): Response
    {
        $denied = $this->accessGuard->denyUnlessAccessLevel($request, AccessLevel::ADMIN);
        if (null !== $denied) {
            return $denied;
        }

        $resources = array_map(
            static fn (ApiEndpointDefinition $definition): array => self::resource($definition),
            array_values(array_filter(
                $this->endpoints->endpoints(),
                static fn (ApiEndpointDefinition $definition): bool => self::isAdminEndpoint($definition),
            )),
        );

        return $this->responder->data($resources, meta: [
            'count' => count($resources),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function resource(ApiEndpointDefinition $definition): array
    {
        return [
            'type' => 'api_endpoint',
            'id' => $definition->operationId(),
            'attributes' => [
                'method' => $definition->method(),
                'path' => $definition->path(),
                'owner' => $definition->owner(),
                'summary' => $definition->summary(),
                'tags' => $definition->tags(),
                'allow_public' => $definition->allowsPublic(),
            ],
        ];
    }

    private static function isAdminEndpoint(ApiEndpointDefinition $definition): bool
    {
        return '/api/v1/admin' === $definition->path()
            || str_starts_with($definition->path(), '/api/v1/admin/');
    }
}
