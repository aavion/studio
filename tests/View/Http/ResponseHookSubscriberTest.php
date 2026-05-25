<?php

declare(strict_types=1);

namespace App\Tests\View\Http;

use App\Core\Event\PublicEventDispatcher;
use App\Core\Event\PublicEventHookRegistry;
use App\Debug\StudioDebugCollector;
use App\View\Event\OutputGeneratedEvent;
use App\View\Event\ResponseHeadersEvent;
use App\View\Http\ResponseHookSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ResponseHookSubscriberTest extends TestCase
{
    public function testItAppliesResponseHeaderHookChanges(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ResponseHeadersEvent::class, static function (ResponseHeadersEvent $event): void {
            $event->setHeader('X-Studio-Demo', 'enabled');
            $event->removeHeader('X-Remove-Me');
        });
        $response = new Response('<html></html>', 200, [
            'Content-Type' => 'text/html',
            'X-Remove-Me' => 'yes',
        ]);

        $this->subscriber($dispatcher)->onKernelResponse($this->responseEvent($response));

        self::assertSame('enabled', $response->headers->get('X-Studio-Demo'));
        self::assertFalse($response->headers->has('X-Remove-Me'));
    }

    public function testItAppliesHtmlOutputHookChanges(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(OutputGeneratedEvent::class, static function (OutputGeneratedEvent $event): void {
            $event->appendContent('<!-- studio-debug -->');
        });
        $response = new Response('<html></html>', 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Length' => '13',
        ]);

        $this->subscriber($dispatcher)->onKernelResponse($this->responseEvent($response));

        self::assertSame('<html></html><!-- studio-debug -->', $response->getContent());
        self::assertFalse($response->headers->has('Content-Length'));
    }

    public function testItDoesNotApplyOutputHookToNonHtmlResponses(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(OutputGeneratedEvent::class, static function (OutputGeneratedEvent $event): void {
            $event->setContent('changed');
        });
        $response = new Response('{"ok":true}', 200, [
            'Content-Type' => 'application/json',
        ]);

        $this->subscriber($dispatcher)->onKernelResponse($this->responseEvent($response));

        self::assertSame('{"ok":true}', $response->getContent());
    }

    public function testItKeepsOriginalOutputWhenHookListenerFails(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(OutputGeneratedEvent::class, static function (OutputGeneratedEvent $event): void {
            $event->setContent('changed');
            throw new \RuntimeException('Output failed');
        });
        $response = new Response('<html></html>', 200, [
            'Content-Type' => 'text/html',
        ]);

        $this->subscriber($dispatcher)->onKernelResponse($this->responseEvent($response));

        self::assertSame('<html></html>', $response->getContent());
    }

    public function testItAppendsDebugCommentWhenCollectorIsEnabled(): void
    {
        $dispatcher = new EventDispatcher();
        $collector = new StudioDebugCollector(true);
        $response = new Response('<html></html>', 200, [
            'Content-Type' => 'text/html',
        ]);

        (new ResponseHookSubscriber(
            new PublicEventDispatcher($dispatcher, new PublicEventHookRegistry(), $collector),
            $collector,
        ))->onKernelResponse($this->responseEvent($response));

        self::assertStringContainsString('<!-- studio-debug', (string) $response->getContent());
        self::assertStringContainsString('ResponseHeadersEvent', (string) $response->getContent());
        self::assertStringContainsString('OutputGeneratedEvent', (string) $response->getContent());
    }

    private function subscriber(EventDispatcher $dispatcher): ResponseHookSubscriber
    {
        return new ResponseHookSubscriber(new PublicEventDispatcher($dispatcher, new PublicEventHookRegistry()));
    }

    private function responseEvent(Response $response): ResponseEvent
    {
        return new ResponseEvent(
            new class implements HttpKernelInterface {
                public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
                {
                    return new Response();
                }
            },
            Request::create('/demo'),
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );
    }
}
