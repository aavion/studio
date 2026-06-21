<?php

declare(strict_types=1);

namespace App\Tests\View\Http;

use App\Content\Read\PublishedContentResolver;
use App\Content\Render\ContentFieldsetRenderer;
use App\Core\Log\AccessRequestMetadata;
use App\Setup\SetupCompletionMarker;
use App\View\Http\HttpErrorRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final class HttpErrorRendererTest extends TestCase
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
        unset($_SERVER[SetupCompletionMarker::KEY], $_ENV[SetupCompletionMarker::KEY]);

        if (null !== $this->previousServerValue) {
            $_SERVER[SetupCompletionMarker::KEY] = $this->previousServerValue;
        }

        if (null !== $this->previousEnvValue) {
            $_ENV[SetupCompletionMarker::KEY] = $this->previousEnvValue;
        }

        is_string($this->previousPutenvValue)
            ? putenv(SetupCompletionMarker::KEY.'='.$this->previousPutenvValue)
            : putenv(SetupCompletionMarker::KEY);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function setupBareStatusCases(): iterable
    {
        foreach (Response::$statusTexts as $statusCode => $statusText) {
            if ($statusCode >= 400 && $statusCode < 600) {
                yield sprintf('%d %s', $statusCode, $statusText) => [$statusCode];
            }
        }
    }

    #[DataProvider('setupBareStatusCases')]
    public function testItReturnsBareKnownErrorResponsesBeforeSetupCompletion(int $statusCode): void
    {
        $response = $this->renderer()->resolve($statusCode, Request::create('/setup/missing'));

        self::assertSame($statusCode, $response->getStatusCode());
        self::assertStringContainsString(sprintf(
            '%d - %s',
            $statusCode,
            htmlspecialchars(Response::$statusTexts[$statusCode], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        ), (string) $response->getContent());
        self::assertStringContainsString('<pre><strong>Request-ID:</strong>', (string) $response->getContent());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
    }

    public function testBareResponseKeepsAdditionalHeadersAndContext(): void
    {
        $request = Request::create('/setup/review');
        $request->attributes->set('_access_request_id', 'req-test-123');
        $response = $this->renderer()->bare(Response::HTTP_TOO_MANY_REQUESTS, $request, [
            'bare_context' => 'retry-after: 60',
        ], ['Retry-After' => '60']);

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        self::assertStringContainsString('429 - Too Many Requests', (string) $response->getContent());
        self::assertStringContainsString('<p>retry-after: 60</p>', (string) $response->getContent());
        self::assertStringContainsString('<pre><strong>Request-ID:</strong> req-test-123</pre>', (string) $response->getContent());
        self::assertSame('60', $response->headers->get('Retry-After'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testResolveCanForceBareResponseAfterSetupCompletion(): void
    {
        $_SERVER[SetupCompletionMarker::KEY] = '1';
        $request = Request::create('/blocked');
        $request->attributes->set(AccessRequestMetadata::REQUEST_ID_ATTRIBUTE, 'req-forced');
        $response = $this->renderer()->resolve(Response::HTTP_FORBIDDEN, $request, [
            'bare_context' => '<blocked>',
        ], forceBare: true);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertStringContainsString('403 - Forbidden', (string) $response->getContent());
        self::assertStringContainsString('<p>&lt;blocked&gt;</p>', (string) $response->getContent());
        self::assertStringContainsString('<pre><strong>Request-ID:</strong> req-forced</pre>', (string) $response->getContent());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    private function renderer(): HttpErrorRenderer
    {
        return new HttpErrorRenderer(
            (new ReflectionClass(Environment::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(PublishedContentResolver::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(ContentFieldsetRenderer::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(Security::class))->newInstanceWithoutConstructor(),
            new SetupCompletionMarker(),
            new AccessRequestMetadata(),
            dirname(__DIR__, 2),
            'test',
        );
    }
}
