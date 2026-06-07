<?php

declare(strict_types=1);

namespace App\Api\Security;

use App\Api\Endpoint\ApiEndpointRegistry;
use App\Api\Http\ApiRequestContext;
use App\Api\Http\ApiResponder;
use App\Core\Message\Message;
use App\Security\SecurityMessageCode;
use App\Security\SecurityMessageKey;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class ApiEndpointAccessSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ApiEndpointRegistry $endpoints,
        private ApiResponder $responder,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 1],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/v1')) {
            return;
        }

        $context = ApiRequestContext::fromRequest($request);
        if ($context instanceof ApiRequestContext && $context->isAuthenticated()) {
            return;
        }

        $endpoint = $this->endpoints->endpointForRequest($request);

        if (null === $endpoint) {
            return;
        }

        if (!$endpoint->allowsPublic() || !$this->isPublicReadMethod($request)) {
            $event->setResponse($this->responder->error(
                Message::warning(
                    SecurityMessageCode::API_KEY_AUTHENTICATION_FAILED,
                    SecurityMessageKey::API_KEY_AUTHENTICATION_FAILED,
                ),
                Response::HTTP_UNAUTHORIZED,
                $request,
                headers: ['WWW-Authenticate' => 'Bearer realm="Studio API"'],
            ));

            return;
        }

        ApiRequestContext::anonymous()->attachTo($request);
    }

    private function isPublicReadMethod(Request $request): bool
    {
        return in_array($request->getMethod(), [
            Request::METHOD_GET,
            Request::METHOD_HEAD,
            Request::METHOD_OPTIONS,
        ], true);
    }
}
