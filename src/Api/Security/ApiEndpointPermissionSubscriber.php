<?php

declare(strict_types=1);

namespace App\Api\Security;

use App\Api\Endpoint\ApiEndpointAccessPolicy;
use App\Api\Endpoint\ApiEndpointRegistry;
use App\Api\Http\ApiRequestContext;
use App\Api\Http\ApiResponder;
use App\Core\Access\AccessActor;
use App\Core\Message\Message;
use App\Security\SecurityMessageCode;
use App\Security\SecurityMessageKey;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class ApiEndpointPermissionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ApiEndpointRegistry $endpoints,
        private ApiEndpointAccessPolicy $policy,
        private ApiResponder $responder,
        private ApiRequestMethodPolicy $methodPolicy = new ApiRequestMethodPolicy(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', -1],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $event->hasResponse()) {
            return;
        }

        $request = $event->getRequest();
        if (!$this->methodPolicy->isApiV1Request($request)) {
            return;
        }

        $endpoint = $this->endpoints->endpointForRequest($request);
        if (null === $endpoint) {
            return;
        }

        $context = ApiRequestContext::fromRequest($request);
        $actor = $context?->actor() ?? AccessActor::anonymous();
        $minimumAccessLevel = $this->policy->minimumAccessLevel($endpoint);

        if ($actor->accessLevel() >= $minimumAccessLevel) {
            return;
        }

        $event->setResponse($this->responder->error(
            Message::warning(
                SecurityMessageCode::API_KEY_PERMISSION_DENIED,
                SecurityMessageKey::API_KEY_PERMISSION_DENIED,
                context: [
                    'required_access_level' => $minimumAccessLevel,
                    'actor_access_level' => $actor->accessLevel(),
                    'authenticated' => $context?->isAuthenticated() ?? false,
                    'operation_id' => $endpoint->operationId(),
                ],
            ),
            Response::HTTP_FORBIDDEN,
            $request,
        ));
    }
}
