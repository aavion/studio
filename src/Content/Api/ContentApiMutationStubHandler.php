<?php

declare(strict_types=1);

namespace App\Content\Api;

use App\Api\ApiMessageCode;
use App\Api\ApiMessageKey;
use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointHandlerInterface;
use App\Api\Http\ApiResponder;
use App\Core\Message\Message;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ContentApiMutationStubHandler implements ApiEndpointHandlerInterface
{
    public function __construct(private ApiResponder $responder)
    {
    }

    public function apiEndpointHandlerKey(): string
    {
        return ContentApiEndpointProvider::HANDLER_CONTENT_MUTATION_STUB;
    }

    public function handle(Request $request, ApiEndpointDefinition $endpoint): Response
    {
        // Advisory placeholder only: the finalized Editor/Content domain commands must own validation,
        // revision semantics, ACL feedback, and the stable API contract. Do not force the content model to
        // match this stub shape; replace/remove this note when real handlers make the placeholder obsolete.
        return $this->responder->error(
            Message::warning(ApiMessageCode::API_OPERATION_NOT_IMPLEMENTED, ApiMessageKey::API_OPERATION_NOT_IMPLEMENTED, [
                '%operation%' => $endpoint->operationId(),
            ], [
                'operation' => $endpoint->operationId(),
                'method' => $request->getMethod(),
                'path' => $request->getPathInfo(),
            ]),
            Response::HTTP_NOT_IMPLEMENTED,
            $request,
        );
    }
}
