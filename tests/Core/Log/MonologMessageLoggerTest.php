<?php

declare(strict_types=1);

namespace App\Tests\Core\Log;

use App\Core\Log\MonologMessageLogger;
use App\Core\Message\Message;
use App\Core\Operation\OperationMessageCode;
use App\Core\Operation\OperationMessageKey;
use App\Core\Operation\Process\ProcessMessageCode;
use App\Core\Operation\Process\ProcessMessageKey;
use App\Core\Package\PackageMessageCode;
use App\Core\Package\PackageMessageKey;
use App\Setup\SetupMessageCode;
use App\Setup\SetupMessageKey;
use Monolog\Handler\AbstractHandler;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MonologMessageLoggerTest extends TestCase
{
    private TestHandler $handler;
    private MonologMessageLogger $logger;

    protected function setUp(): void
    {
        $this->handler = new TestHandler();
        $monolog = new Logger('message');
        $monolog->pushHandler($this->handler);
        $this->logger = new MonologMessageLogger($monolog);
    }

    public function testItWritesMessagesToMonologWithStructuredContext(): void
    {
        $error = Message::error(
            ProcessMessageCode::PROCESS_COMMAND_FAILED,
            ProcessMessageKey::PROCESS_COMMAND_FAILED,
            ['%command%' => 'bin/console demo'],
            ['exit_code' => 1, 'database_password' => 'secret'],
        );
        $message = Message::info(
            PackageMessageCode::PACKAGE_DISCOVERY_COMPLETED,
            PackageMessageKey::PACKAGE_DISCOVERY_COMPLETED,
            ['%count%' => 1],
            ['api_token' => 'abc123'],
        );

        $this->logger->logBatch([
            [
                'message' => $error,
                'context' => [
                    'queue' => 'demo',
                    'app_secret' => 'hidden',
                ],
            ],
            [
                'message' => $message,
                'context' => [
                    'queue' => 'demo',
                ],
            ],
        ]);

        $records = $this->handler->getRecords();

        self::assertCount(2, $records);
        self::assertSame(Level::Error, $records[0]->level);
        self::assertSame('message.process.command_failed', $records[0]->message);
        self::assertSame('process.command_failed', $records[0]->context['code']);
        self::assertSame('demo', $records[0]->context['queue']);
        self::assertSame('[redacted]', $records[0]->context['message_context']['database_password']);
        self::assertSame('[redacted]', $records[0]->context['app_secret']);
        self::assertSame(Level::Info, $records[1]->level);
        self::assertSame('[redacted]', $records[1]->context['message_context']['api_token']);
    }

    public function testItMapsSuccessAndExceptionLevelsToPsrLevels(): void
    {
        $this->logger->log(Message::success(PackageMessageKey::PACKAGE_DISCOVERY_COMPLETED));
        $this->logger->log(Message::exception(
            OperationMessageCode::OPERATION_EXCEPTION,
            OperationMessageKey::OPERATION_EXCEPTION,
            context: ['exception' => 'RuntimeException'],
        ));

        $records = $this->handler->getRecords();

        self::assertSame(Level::Notice, $records[0]->level);
        self::assertSame(Level::Critical, $records[1]->level);
    }

    public function testItDoesNotLogEmptyBatches(): void
    {
        $this->logger->logBatch([]);

        self::assertSame([], $this->handler->getRecords());
    }

    public function testItLogsSingleMessages(): void
    {
        $this->logger->log(Message::info(SetupMessageCode::SETUP_LANGUAGE_SELECTED, SetupMessageKey::SETUP_LANGUAGE_SELECTED, [
            '%language%' => 'en',
        ]), [
            'operation' => 'setup.run',
            'token' => 'secret',
        ]);

        $records = $this->handler->getRecords();

        self::assertCount(1, $records);
        self::assertSame('message.setup.language_selected', $records[0]->message);
        self::assertSame('[redacted]', $records[0]->context['token']);
    }

    public function testItDeduplicatesIdenticalMessageEntries(): void
    {
        $message = Message::info(PackageMessageCode::PACKAGE_DISCOVERY_COMPLETED, PackageMessageKey::PACKAGE_DISCOVERY_COMPLETED, [
            '%count%' => 1,
        ]);

        $this->logger->logBatch([
            [
                'message' => $message,
                'context' => [
                    'operation' => 'package.discovery.run',
                ],
            ],
            [
                'message' => $message,
                'context' => [
                    'operation' => 'package.discovery.run',
                ],
            ],
        ]);

        self::assertCount(1, $this->handler->getRecords());
    }

    public function testItKeepsMessageLoggingFailuresNonFatal(): void
    {
        $monolog = new Logger('message');
        $monolog->pushHandler(new ThrowingMessageLogHandler());
        $logger = new MonologMessageLogger($monolog);

        $logger->log(Message::info(PackageMessageCode::PACKAGE_DISCOVERY_COMPLETED, PackageMessageKey::PACKAGE_DISCOVERY_COMPLETED));

        self::assertTrue(true);
    }
}

final class ThrowingMessageLogHandler extends AbstractHandler
{
    public function handle(LogRecord $record): bool
    {
        throw new RuntimeException('log target unavailable');
    }
}
