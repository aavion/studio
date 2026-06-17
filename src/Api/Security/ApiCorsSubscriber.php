<?php

declare(strict_types=1);

namespace App\Api\Security;

use App\Api\ApiFeaturePolicy;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class ApiCorsSubscriber implements EventSubscriberInterface
{
    private const ALLOWED_METHODS = 'GET, HEAD, OPTIONS, POST, PUT, PATCH, DELETE';
    private const ALLOWED_HEADERS = 'Authorization, Content-Type, Accept, Accept-Language, X-Correlation-ID, X-Request-ID';
    private const EXPOSED_HEADERS = 'X-Request-ID, X-Correlation-ID';

    public function __construct(private ApiFeaturePolicy $apiFeaturePolicy)
    {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 512],
            KernelEvents::RESPONSE => ['onKernelResponse', 0],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $event->hasResponse()) {
            return;
        }

        $request = $event->getRequest();
        if (!$this->isApiRequest($request) || !$this->isPreflight($request)) {
            return;
        }

        if ($this->hasActualAuthorizationHeader($request)) {
            return;
        }

        $origin = $this->allowedOrigin($request);
        if (null === $origin) {
            return;
        }

        $response = new Response('', Response::HTTP_NO_CONTENT);
        $this->applyHeaders($response, $origin);
        $event->setResponse($response);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$this->isApiRequest($request)) {
            return;
        }

        $origin = $this->allowedOrigin($request);
        if (null === $origin) {
            return;
        }

        $this->applyHeaders($event->getResponse(), $origin);
    }

    private function isApiRequest(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), '/api/v1');
    }

    private function isPreflight(Request $request): bool
    {
        return $request->isMethod(Request::METHOD_OPTIONS)
            && is_string($request->headers->get('Origin'))
            && is_string($request->headers->get('Access-Control-Request-Method'));
    }

    private function hasActualAuthorizationHeader(Request $request): bool
    {
        return '' !== trim((string) $request->headers->get('Authorization', ''));
    }

    private function allowedOrigin(Request $request): ?string
    {
        if (!$this->apiFeaturePolicy->corsEnabled()) {
            return null;
        }

        $origin = trim((string) $request->headers->get('Origin', ''));
        if ('' === $origin) {
            return null;
        }

        $allowedOrigins = $this->apiFeaturePolicy->allowedCorsOrigins();
        if (in_array('*', $allowedOrigins, true)) {
            return '*';
        }

        return in_array($origin, $allowedOrigins, true) ? $origin : null;
    }

    private function applyHeaders(Response $response, string $origin): void
    {
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Methods', self::ALLOWED_METHODS);
        $response->headers->set('Access-Control-Allow-Headers', self::ALLOWED_HEADERS);
        $response->headers->set('Access-Control-Expose-Headers', self::EXPOSED_HEADERS);
        $response->headers->set('Access-Control-Max-Age', '600');

        if ('*' !== $origin) {
            $response->headers->set('Vary', 'Origin', false);
        }
    }
}
