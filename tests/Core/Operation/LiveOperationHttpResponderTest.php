<?php

declare(strict_types=1);

namespace App\Tests\Core\Operation;

use App\Core\Message\Message;
use App\Core\Operation\Live\LiveOperationHttpResponder;
use App\Core\Operation\OperationMessageCode;
use App\Core\Operation\OperationMessageKey;
use App\Core\Output\JsonOutputRenderer;
use App\Core\Workflow\WorkflowResult;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

final class LiveOperationHttpResponderTest extends TestCase
{
    public function testItRedactsDirectWorkflowResultResponses(): void
    {
        $issue = Message::warning(
            OperationMessageCode::OPERATION_EXCEPTION,
            OperationMessageKey::OPERATION_EXCEPTION,
            ['%operation%' => 'Cache clear', '%message%' => '/tmp/private/path is unreadable'],
            [
                'message' => '/tmp/private/path is unreadable',
                'reason' => '/tmp/private/reason',
                'database_password' => 'db-secret',
            ],
        );
        $result = WorkflowResult::failed([$issue], [
            'operation' => 'backend.cache_clear',
            'message' => '/tmp/private/path is unreadable',
            'reason' => '/tmp/private/reason',
            'database_password' => 'db-secret',
        ]);

        $response = (new LiveOperationHttpResponder(
            new JsonOutputRenderer(),
            new TestLiveOperationUrlGenerator(),
        ))->render($result);
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        self::assertIsString($encoded);
        self::assertFalse($payload['success']);
        self::assertSame('[redacted]', $payload['issues'][0]['parameters']['%message%']);
        self::assertSame('[redacted]', $payload['issues'][0]['context']['message']);
        self::assertSame('[redacted]', $payload['issues'][0]['context']['reason']);
        self::assertSame('[redacted]', $payload['issues'][0]['context']['database_password']);
        self::assertSame('[redacted]', $payload['context']['message']);
        self::assertSame('[redacted]', $payload['context']['reason']);
        self::assertSame('[redacted]', $payload['context']['database_password']);
        self::assertStringNotContainsString('/tmp/private/path', $encoded);
        self::assertStringNotContainsString('/tmp/private/reason', $encoded);
        self::assertStringNotContainsString('db-secret', $encoded);
    }

    public function testItKeepsLiveOperationStatusUrlForStartedRuns(): void
    {
        $result = WorkflowResult::success([
            'operation_id' => '1234567890abcdef1234567890abcdef',
            'token' => 'live-token',
            'operation' => 'backend.cache_clear',
            'label' => 'Cache clear',
            'status' => 'queued',
            'admin_password' => 'admin-secret',
        ]);

        $response = (new LiveOperationHttpResponder(
            new JsonOutputRenderer(),
            new TestLiveOperationUrlGenerator(),
        ))->render($result);
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertTrue($payload['success']);
        self::assertSame('/api/live/operations/1234567890abcdef1234567890abcdef?token=live-token', $payload['value']['status_url']);
        self::assertArrayNotHasKey('admin_password', $payload['value']);
    }
}

final class TestLiveOperationUrlGenerator implements UrlGeneratorInterface
{
    private RequestContext $context;

    public function __construct()
    {
        $this->context = new RequestContext();
    }

    public function setContext(RequestContext $context): void
    {
        $this->context = $context;
    }

    public function getContext(): RequestContext
    {
        return $this->context;
    }

    public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
    {
        return '/api/live/operations/'.($parameters['operationId'] ?? '').'?token='.($parameters['token'] ?? '');
    }
}
