<?php

declare(strict_types=1);

namespace App\Content\Api;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointHandlerInterface;
use App\Api\Http\ApiResponder;
use App\Api\Security\ApiAccessGuard;
use App\Core\Access\AccessLevel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class SchemaApiHandler implements ApiEndpointHandlerInterface
{
    public function __construct(
        private SchemaApiReadModel $readModel,
        private ApiAccessGuard $accessGuard,
        private ApiResponder $responder,
    ) {
    }

    public function apiEndpointHandlerKey(): string
    {
        return SchemaApiEndpointProvider::HANDLER_SCHEMAS_INDEX;
    }

    public function handle(Request $request, ApiEndpointDefinition $endpoint): Response
    {
        $denied = $this->accessGuard->denyUnlessAccessLevel($request, AccessLevel::AUTHOR);
        if (null !== $denied) {
            return $denied;
        }

        $schemas = $this->readModel->schemas();

        return $this->responder->data($schemas, meta: ['count' => count($schemas)]);
    }
}
