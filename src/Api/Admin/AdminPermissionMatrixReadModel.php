<?php

declare(strict_types=1);

namespace App\Api\Admin;

use App\Api\Endpoint\ApiEndpointAccessPolicy;
use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointRegistry;
use Symfony\Component\HttpFoundation\Request;

final readonly class AdminPermissionMatrixReadModel
{
    public function __construct(
        private ApiEndpointRegistry $endpoints,
        private ApiEndpointAccessPolicy $policy,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function resources(): array
    {
        return array_map(
            fn (ApiEndpointDefinition $endpoint): array => $this->resource($endpoint),
            $this->endpoints->endpoints(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function resource(ApiEndpointDefinition $endpoint): array
    {
        $id = strtolower($endpoint->method()).' '.substr($endpoint->path(), strlen('/api/v1'));
        $requiresWriteKey = !$this->isSafeMethod($endpoint->method());

        return [
            'type' => 'api_endpoint_permission',
            'id' => $id,
            'attributes' => [
                'method' => $endpoint->method(),
                'path' => $endpoint->path(),
                'operation_id' => $endpoint->operationId(),
                'summary' => $endpoint->summary(),
                'owner' => $endpoint->owner(),
                'tags' => $endpoint->tags(),
                'allow_public' => $endpoint->allowsPublic(),
                'requires_api_key' => $this->policy->requiresApiKey($endpoint),
                'required_access_level' => $this->policy->minimumAccessLevel($endpoint),
                'required_role' => $this->policy->minimumRole($endpoint),
                'key_capability' => $this->policy->keyCapability($endpoint),
                'requires_read_write_key' => $requiresWriteKey,
                'domain_acl_enforced_by_handler' => true,
                'request_body_required' => null !== $endpoint->requestSchema(),
            ],
            'links' => [
                'openapi' => '/api/v1/openapi.json',
            ],
        ];
    }

    private function isSafeMethod(string $method): bool
    {
        return in_array($method, [
            Request::METHOD_GET,
            Request::METHOD_HEAD,
            Request::METHOD_OPTIONS,
        ], true);
    }
}
