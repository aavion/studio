<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Access\AccessActor;
use App\Core\Access\AccessLevel;
use App\Core\Output\JsonOutputRenderer;
use App\Entity\UserAccount;
use App\Live\LiveEndpointHandlerRegistry;
use App\Live\LiveEndpointRegistry;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class LiveEndpointController
{
    public function __construct(
        private LiveEndpointRegistry $endpoints,
        private LiveEndpointHandlerRegistry $handlers,
        private JsonOutputRenderer $json,
        private Security $security,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route('/api/live/{extensionSlug}/{resourcePath}', name: 'api_live_extension_dispatch', requirements: ['extensionSlug' => '[a-z0-9]+(?:-[a-z0-9]+)*', 'resourcePath' => '.+'], methods: ['GET'], priority: -100)]
    public function dispatch(Request $request): Response
    {
        $endpoint = $this->endpoints->endpointForRequest($request);
        if (null === $endpoint) {
            return $this->json->render([
                'status' => 'not_found',
                'message' => $this->translator->trans('ui.live_endpoint.not_found'),
                'next_poll_ms' => 0,
            ], Response::HTTP_NOT_FOUND);
        }

        if (!$this->endpointMatchesRouteSlug($request, $endpoint->path())) {
            return $this->json->render([
                'status' => 'not_found',
                'message' => $this->translator->trans('ui.live_endpoint.not_found'),
                'next_poll_ms' => 0,
            ], Response::HTTP_NOT_FOUND);
        }

        $minimumAccessLevel = $endpoint->minimumAccessLevel() ?? AccessLevel::PUBLIC;
        $user = $this->security->getUser();
        $actor = $user instanceof UserAccount ? AccessActor::fromUserAccount($user) : AccessActor::anonymous();

        if ($actor->accessLevel() < $minimumAccessLevel) {
            return $this->json->render([
                'status' => 'forbidden',
                'message' => $this->translator->trans('ui.live_endpoint.forbidden'),
                'next_poll_ms' => 0,
            ], Response::HTTP_FORBIDDEN);
        }

        $handler = $this->handlers->handler($endpoint->handlerKey());
        if (null === $handler) {
            return $this->json->render([
                'status' => 'unavailable',
                'message' => $this->translator->trans('ui.live_endpoint.handler_unavailable'),
                'next_poll_ms' => 0,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $handler->handleLiveRequest($request, $endpoint);
    }

    private function endpointMatchesRouteSlug(Request $request, string $endpointPath): bool
    {
        $slug = (string) $request->attributes->get('extensionSlug', '');
        if ('' === $slug && 1 === preg_match('#^/api/live/([a-z0-9]+(?:-[a-z0-9]+)*)/#', $request->getPathInfo(), $matches)) {
            $slug = $matches[1];
        }

        return '' !== $slug && str_starts_with($endpointPath, '/api/live/'.$slug.'/');
    }
}
