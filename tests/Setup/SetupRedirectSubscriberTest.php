<?php

declare(strict_types=1);

namespace App\Tests\Setup;

use App\Setup\SetupCompletionMarker;
use App\Setup\SetupRedirectSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class SetupRedirectSubscriberTest extends TestCase
{
    private mixed $previousServerValue = null;
    private mixed $previousEnvValue = null;
    private mixed $previousPutenvValue = false;

    protected function setUp(): void
    {
        $this->previousServerValue = $_SERVER[SetupCompletionMarker::KEY] ?? null;
        $this->previousEnvValue = $_ENV[SetupCompletionMarker::KEY] ?? null;
        $this->previousPutenvValue = getenv(SetupCompletionMarker::KEY);
        unset($_SERVER[SetupCompletionMarker::KEY], $_ENV[SetupCompletionMarker::KEY]);
        putenv(SetupCompletionMarker::KEY);
    }

    protected function tearDown(): void
    {
        $this->restoreEnvironment();
    }

    public function testItRedirectsPublicRequestsToSetupBeforeCompletion(): void
    {
        $event = $this->event('/anything');

        $this->subscriber()->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/setup', $response->headers->get('Location'));
    }

    public function testItAllowsSetupAndStaticToolingPathsBeforeCompletion(): void
    {
        $subscriber = $this->subscriber();

        foreach (['/setup', '/setup/recovery', '/assets/app.css', '/build/app.js', '/_profiler', '/_wdt/token', '/favicon.ico'] as $path) {
            $event = $this->event($path);
            $subscriber->onKernelRequest($event);

            self::assertFalse($event->hasResponse(), $path);
        }
    }

    public function testItDoesNothingAfterSetupCompletion(): void
    {
        $_SERVER[SetupCompletionMarker::KEY] = '1';
        $event = $this->event('/anything');

        $this->subscriber()->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    private function subscriber(): SetupRedirectSubscriber
    {
        return new SetupRedirectSubscriber(new SetupCompletionMarker(), dirname(__DIR__, 2), 'test');
    }

    private function event(string $path): RequestEvent
    {
        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create($path),
            HttpKernelInterface::MAIN_REQUEST,
        );
    }

    private function restoreEnvironment(): void
    {
        unset($_SERVER[SetupCompletionMarker::KEY], $_ENV[SetupCompletionMarker::KEY]);

        if (null !== $this->previousServerValue) {
            $_SERVER[SetupCompletionMarker::KEY] = $this->previousServerValue;
        }

        if (null !== $this->previousEnvValue) {
            $_ENV[SetupCompletionMarker::KEY] = $this->previousEnvValue;
        }

        if (is_string($this->previousPutenvValue)) {
            putenv(SetupCompletionMarker::KEY.'='.$this->previousPutenvValue);

            return;
        }

        putenv(SetupCompletionMarker::KEY);
    }
}
