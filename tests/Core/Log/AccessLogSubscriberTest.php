<?php

declare(strict_types=1);

namespace App\Tests\Core\Log;

use App\Core\Access\AccessMessageKey;
use App\Core\Log\AccessLogSubscriber;
use App\Core\Log\AccessLoggerInterface;
use App\Core\Log\AccessRequestMetadata;
use App\Core\Message\Message;
use App\Core\Message\MessageReporterInterface;
use App\Core\Statistics\AccessStatisticsRecorderInterface;
use App\Core\Statistics\VisitorIdGenerator;
use App\Database\DatabaseReadyState;
use App\Security\AutoBan\AutoBanRequestSubscriber;
use App\Setup\SetupCompletionMarker;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class AccessLogSubscriberTest extends TestCase
{
    public function testItKeepsTheResponsePathAliveWhenAccessLoggingFails(): void
    {
        $statisticsRecorder = new RecordingAccessStatisticsRecorder();
        $reporter = new RecordingAccessMessageReporter();
        $request = Request::create('/docs');
        $response = new Response('OK', 200);

        (new AccessLogSubscriber(
            new FailingAccessLogger(),
            $statisticsRecorder,
            new AccessRequestMetadata(),
            new VisitorIdGenerator('test-secret'),
            $reporter,
        ))->onKernelResponse(new ResponseEvent(
            new AccessSubscriberTestKernel(),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        ));

        self::assertCount(1, $statisticsRecorder->records);
        self::assertCount(1, $reporter->records);
        self::assertSame(VisitorIdGenerator::COOKIE_NAME, $response->headers->getCookies()[0]?->getName());
        self::assertSame(AccessMessageKey::ACCESS_LOG_FAILED, $reporter->records[0]['message']->translationKey());
        self::assertSame('access.log', $reporter->records[0]['context']['operation']);
    }

    public function testItLogsSetupRequestsWhileDatabaseIsNotReady(): void
    {
        $accessLogger = new RecordingAccessLogger();
        $statisticsRecorder = new RecordingAccessStatisticsRecorder();
        $request = Request::create('/setup/admin');
        $response = new Response('OK', 200);

        (new AccessLogSubscriber(
            $accessLogger,
            $statisticsRecorder,
            new AccessRequestMetadata(),
            new VisitorIdGenerator('test-secret'),
            null,
            new DatabaseReadyState(new SetupCompletionMarker(), sys_get_temp_dir().'/missing-system-project', 'test'),
        ))->onKernelResponse(new ResponseEvent(
            new AccessSubscriberTestKernel(),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        ));

        self::assertSame(['/setup/admin'], $accessLogger->paths);
        self::assertSame([], $statisticsRecorder->records);
    }

    public function testItLogsAutoBanForbiddenResponsesForAuditCorrelation(): void
    {
        $accessLogger = new RecordingAccessLogger();
        $statisticsRecorder = new RecordingAccessStatisticsRecorder();
        $request = Request::create('/missing', server: ['REMOTE_ADDR' => '203.0.113.10']);
        $request->attributes->set(AutoBanRequestSubscriber::PASSIVE_SIGNAL_SKIP_ATTRIBUTE, true);
        $response = new Response('blocked', Response::HTTP_FORBIDDEN);

        (new AccessLogSubscriber(
            $accessLogger,
            $statisticsRecorder,
            new AccessRequestMetadata(),
            new VisitorIdGenerator('test-secret'),
        ))->onKernelResponse(new ResponseEvent(
            new AccessSubscriberTestKernel(),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        ));

        self::assertSame(['/missing'], $accessLogger->paths);
        self::assertSame([
            ['path' => '/missing', 'status' => Response::HTTP_FORBIDDEN],
        ], $statisticsRecorder->records);
    }

    public function testItLogsAutoBanForbiddenResponsesForIgnorablePaths(): void
    {
        $accessLogger = new RecordingAccessLogger();
        $statisticsRecorder = new RecordingAccessStatisticsRecorder();
        $request = Request::create('/favicon.ico', server: ['REMOTE_ADDR' => '203.0.113.10']);
        $request->attributes->set(AutoBanRequestSubscriber::PASSIVE_SIGNAL_SKIP_ATTRIBUTE, true);
        $request->attributes->set(AccessRequestMetadata::FORCE_ACCESS_LOG_ATTRIBUTE, true);
        $response = new Response('blocked', Response::HTTP_FORBIDDEN);

        (new AccessLogSubscriber(
            $accessLogger,
            $statisticsRecorder,
            new AccessRequestMetadata(),
            new VisitorIdGenerator('test-secret'),
        ))->onKernelResponse(new ResponseEvent(
            new AccessSubscriberTestKernel(),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        ));

        self::assertSame(['/favicon.ico'], $accessLogger->paths);
        self::assertSame([], $statisticsRecorder->records);
    }
}

final class RecordingAccessLogger implements AccessLoggerInterface
{
    /**
     * @var list<string>
     */
    public array $paths = [];

    public function log(Request $request, Response $response): void
    {
        $this->paths[] = $request->getPathInfo();
    }
}

final class FailingAccessLogger implements AccessLoggerInterface
{
    public function log(Request $request, Response $response): void
    {
        throw new RuntimeException('Log target is not writable.');
    }
}

final class RecordingAccessStatisticsRecorder implements AccessStatisticsRecorderInterface
{
    /**
     * @var list<array{path: string, status: int}>
     */
    public array $records = [];

    public function record(Request $request, Response $response): void
    {
        $this->records[] = [
            'path' => $request->getPathInfo(),
            'status' => $response->getStatusCode(),
        ];
    }
}

final class RecordingAccessMessageReporter implements MessageReporterInterface
{
    /**
     * @var list<array{message: Message, context: array<string, mixed>}>
     */
    public array $records = [];

    public function report(Message $message, array $context = []): Message
    {
        $this->records[] = [
            'message' => $message,
            'context' => $context,
        ];

        return $message;
    }

    public function reportBatch(iterable $records): array
    {
        return [];
    }
}

final class AccessSubscriberTestKernel implements HttpKernelInterface
{
    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        return new Response();
    }
}
