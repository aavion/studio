<?php

declare(strict_types=1);

namespace App\Core\Extension\Api;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointHandlerInterface;
use App\Api\Endpoint\ApiEndpointNavigationBuilder;
use App\Api\Http\ApiRequestContext;
use App\Api\Http\ApiResponder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ExtensionApiNavigationHandler implements ApiEndpointHandlerInterface
{
    public function __construct(
        private ApiEndpointNavigationBuilder $navigation,
        private ApiResponder $responder,
    ) {
    }

    public function apiEndpointHandlerKey(): string
    {
        return ExtensionApiEndpointProvider::HANDLER_EXTENSIONS_NAVIGATION;
    }

    public function handle(Request $request, ApiEndpointDefinition $endpoint): Response
    {
        $context = ApiRequestContext::fromRequest($request);
        $data = $this->navigation->navigation($endpoint->path(), $context?->isAuthenticated() ?? false);
        $children = $data['attributes']['children'] ?? [];

        return $this->responder->data($data, meta: [
            'child_count' => is_countable($children) ? count($children) : 0,
        ]);
    }
}
