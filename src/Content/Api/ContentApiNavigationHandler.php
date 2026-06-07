<?php

declare(strict_types=1);

namespace App\Content\Api;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointHandlerInterface;
use App\Api\Endpoint\ApiEndpointNavigationBuilder;
use App\Api\Http\ApiRequestContext;
use App\Api\Http\ApiResponder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ContentApiNavigationHandler implements ApiEndpointHandlerInterface
{
    public function __construct(
        private ApiEndpointNavigationBuilder $navigation,
        private ApiResponder $responder,
    ) {
    }

    public function apiEndpointHandlerKey(): string
    {
        return ContentApiEndpointProvider::HANDLER_CONTENT_INDEX;
    }

    public function handle(Request $request, ApiEndpointDefinition $endpoint): Response
    {
        $context = ApiRequestContext::fromRequest($request);
        $data = $this->navigation->navigation($endpoint->path(), $context?->isAuthenticated() ?? false);

        return $this->responder->data($data, meta: [
            'child_count' => count($data['attributes']['children'] ?? []),
        ]);
    }
}
