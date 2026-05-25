<?php

declare(strict_types=1);

namespace App\View\Http;

use App\Core\Event\PublicEventDispatcher;
use App\Debug\StudioDebugCollector;
use App\View\Event\OutputGeneratedEvent;
use App\View\Event\ResponseHeadersEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class ResponseHookSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private PublicEventDispatcher $eventDispatcher,
        private ?StudioDebugCollector $debugCollector = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', -256],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();

        $this->dispatchHeadersHook($request, $response);

        if ($this->isHtmlResponse($response)) {
            $this->dispatchOutputHook($request, $response);
            $this->appendDebugComment($response);
        }
    }

    private function dispatchHeadersHook(Request $request, Response $response): void
    {
        $hook = new ResponseHeadersEvent($request, $response->getStatusCode(), $response->headers->get('Content-Type'));
        $result = $this->eventDispatcher->dispatch($hook, [
            'operation' => 'response_headers',
            'path' => $request->getPathInfo(),
            'status_code' => $response->getStatusCode(),
        ]);

        if (!$result->isSuccess()) {
            return;
        }

        foreach ($hook->removedHeaders() as $header) {
            $response->headers->remove($header);
        }

        foreach ($hook->headers() as $header) {
            $response->headers->set($header['name'], $header['value'], $header['replace']);
        }
    }

    private function dispatchOutputHook(Request $request, Response $response): void
    {
        $content = $response->getContent();
        if (!is_string($content)) {
            return;
        }

        $hook = new OutputGeneratedEvent($request, $content, $response->getStatusCode(), $response->headers->get('Content-Type'));
        $result = $this->eventDispatcher->dispatch($hook, [
            'operation' => 'output_generated',
            'path' => $request->getPathInfo(),
            'status_code' => $response->getStatusCode(),
        ]);

        if (!$result->isSuccess() || $hook->content() === $content) {
            return;
        }

        $response->setContent($hook->content());
        $response->headers->remove('Content-Length');
    }

    private function isHtmlResponse(Response $response): bool
    {
        $contentType = $response->headers->get('Content-Type');

        return null === $contentType || str_contains(strtolower($contentType), 'text/html');
    }

    private function appendDebugComment(Response $response): void
    {
        $comment = $this->debugCollector?->htmlComment() ?? '';
        $content = $response->getContent();

        if ('' === $comment || !is_string($content)) {
            return;
        }

        $response->setContent($content.$comment);
        $response->headers->remove('Content-Length');
    }
}
