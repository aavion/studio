<?php

declare(strict_types=1);

namespace App\Api\Endpoint;

use App\Core\Access\AccessLevel;
use App\Security\UserRole;
use Symfony\Component\HttpFoundation\Request;

final readonly class ApiEndpointAccessPolicy
{
    public function minimumAccessLevel(ApiEndpointDefinition $endpoint): int
    {
        $explicit = $endpoint->minimumAccessLevel();
        if (null !== $explicit) {
            return $explicit;
        }

        if ($endpoint->allowsPublic() && $this->isSafeMethod($endpoint->method())) {
            return AccessLevel::PUBLIC;
        }

        foreach ($endpoint->tags() as $tag) {
            if (str_starts_with($tag, 'backend-admin')) {
                return AccessLevel::ADMIN;
            }

            if (str_starts_with($tag, 'backend-editor')) {
                return AccessLevel::AUTHOR;
            }

            if (str_starts_with($tag, 'frontend-user')) {
                return AccessLevel::USER;
            }
        }

        return AccessLevel::USER;
    }

    public function minimumRole(ApiEndpointDefinition $endpoint): string
    {
        return UserRole::fromAccessLevel($this->minimumAccessLevel($endpoint))->value;
    }

    public function keyCapability(ApiEndpointDefinition $endpoint): string
    {
        return $this->isSafeMethod($endpoint->method()) ? 'read_only_or_read_write' : 'read_write';
    }

    public function requiresApiKey(ApiEndpointDefinition $endpoint): bool
    {
        return !$endpoint->allowsPublic();
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
