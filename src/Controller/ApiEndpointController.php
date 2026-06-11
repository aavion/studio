<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiMessageCode;
use App\Api\ApiMessageKey;
use App\Api\Endpoint\ApiEndpointHandlerRegistry;
use App\Api\Endpoint\ApiEndpointRegistry;
use App\Api\Http\ApiResponder;
use App\Core\Message\Message;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ApiEndpointController
{
    public function __construct(
        private ApiEndpointRegistry $endpoints,
        private ApiEndpointHandlerRegistry $handlers,
        private ApiResponder $responder,
    ) {
    }

    #[Route('/api/v1/{resourcePath}', name: 'api_v1_endpoint_dispatch', requirements: ['resourcePath' => '.+'], methods: ['GET', 'HEAD', 'OPTIONS', 'POST', 'PUT', 'PATCH', 'DELETE'], priority: -100)]
    public function dispatch(Request $request): Response
    {
        $endpoint = $this->endpoints->endpointForRequest($request);
        if (null === $endpoint) {
            return $this->notFound($request);
        }

        $handlerKey = $endpoint->handlerKey();
        $handler = null === $handlerKey ? null : $this->handlers->handler($handlerKey);
        if (null === $handler) {
            return $this->handlerUnavailable($request, $handlerKey);
        }

        return $handler->handle($request, $endpoint);
    }

    private function notFound(Request $request): Response
    {
        return $this->responder->error(
            Message::warning(
                ApiMessageCode::API_ENDPOINT_NOT_FOUND,
                ApiMessageKey::API_ENDPOINT_NOT_FOUND,
                context: [
                    'path' => $request->getPathInfo(),
                    'method' => $request->getMethod(),
                ],
            ),
            Response::HTTP_NOT_FOUND,
            $request,
        );
    }

    private function handlerUnavailable(Request $request, ?string $handlerKey): Response
    {
        return $this->responder->error(
            Message::error(
                ApiMessageCode::API_ENDPOINT_HANDLER_INVALID,
                ApiMessageKey::API_ENDPOINT_HANDLER_INVALID,
                context: [
                    'path' => $request->getPathInfo(),
                    'method' => $request->getMethod(),
                    'handler' => $handlerKey,
                ],
            ),
            Response::HTTP_INTERNAL_SERVER_ERROR,
            $request,
        );
    }
}
