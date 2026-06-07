<?php

declare(strict_types=1);

namespace App\Api\Security;

use App\Api\ApiMessageCode;
use App\Api\ApiMessageKey;
use App\Api\Endpoint\ApiEndpointRegistry;
use App\Api\Http\ApiResponder;
use App\Core\Message\Message;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class ApiContentTypeSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ApiEndpointRegistry $endpoints,
        private ApiResponder $responder,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
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
        if (!str_starts_with($request->getPathInfo(), '/api/v1')) {
            return;
        }

        $endpoint = $this->endpoints->endpointForRequest($request);
        if (null === $endpoint || null === $endpoint->requestSchema()) {
            return;
        }

        if ($this->isJsonContentType($request)) {
            return;
        }

        $event->setResponse($this->responder->error(
            Message::warning(ApiMessageCode::API_UNSUPPORTED_MEDIA_TYPE, ApiMessageKey::API_UNSUPPORTED_MEDIA_TYPE, context: [
                'path' => $request->getPathInfo(),
                'method' => $request->getMethod(),
                'content_type' => $request->headers->get('Content-Type') ?? 'n/a',
                'expected_content_type' => 'application/json',
            ]),
            Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
            $request,
        ));
    }

    private function isJsonContentType(Request $request): bool
    {
        $contentType = strtolower(trim((string) $request->headers->get('Content-Type', '')));
        if ('' === $contentType) {
            return false;
        }

        $mediaType = trim(explode(';', $contentType, 2)[0] ?? $contentType);

        return 'application/json' === $mediaType || str_ends_with($mediaType, '+json');
    }
}
