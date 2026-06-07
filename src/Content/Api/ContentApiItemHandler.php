<?php

declare(strict_types=1);

namespace App\Content\Api;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointHandlerInterface;
use App\Api\Http\ApiRequestContext;
use App\Api\Http\ApiResponder;
use App\Core\Access\AccessActor;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ContentApiItemHandler implements ApiEndpointHandlerInterface
{
    public function __construct(
        private ContentApiItemReadModel $readModel,
        private ApiResponder $responder,
    ) {
    }

    public function apiEndpointHandlerKey(): string
    {
        return ContentApiEndpointProvider::HANDLER_CONTENT_ITEMS;
    }

    public function handle(Request $request, ApiEndpointDefinition $endpoint): Response
    {
        $actor = ApiRequestContext::fromRequest($request)?->actor() ?? AccessActor::anonymous();
        $items = $this->readModel->visibleItems($actor);

        return $this->responder->data($items, meta: ['count' => count($items)]);
    }
}
