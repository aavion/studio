<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Output\JsonOutputRenderer;
use App\Live\LiveEndpointHandlerRegistry;
use App\Live\LiveEndpointRegistry;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class LiveEndpointController
{
    public function __construct(
        private LiveEndpointRegistry $endpoints,
        private LiveEndpointHandlerRegistry $handlers,
        private JsonOutputRenderer $json,
        private Security $security,
    ) {
    }

    #[Route('/api/live/{packageSlug}/{resourcePath}', name: 'api_live_package_dispatch', requirements: ['packageSlug' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'resourcePath' => '.+'], methods: ['GET', 'HEAD', 'OPTIONS', 'POST'], priority: -100)]
    public function dispatch(Request $request): Response
    {
        $endpoint = $this->endpoints->endpointForRequest($request);
        if (null === $endpoint) {
            return $this->json->render([
                'status' => 'not_found',
                'message' => 'Live endpoint not found.',
                'next_poll_ms' => 0,
            ], Response::HTTP_NOT_FOUND);
        }

        if (!$endpoint->allowsPublic() && null === $this->security->getUser()) {
            return $this->json->render([
                'status' => 'forbidden',
                'message' => 'Authentication is required for this live endpoint.',
                'next_poll_ms' => 0,
            ], Response::HTTP_FORBIDDEN);
        }

        $handler = $this->handlers->handler($endpoint->handlerKey());
        if (null === $handler) {
            return $this->json->render([
                'status' => 'unavailable',
                'message' => 'Live endpoint handler is not available.',
                'next_poll_ms' => 0,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $handler->handleLiveRequest($request, $endpoint);
    }
}
