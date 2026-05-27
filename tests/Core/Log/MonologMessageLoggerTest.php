<?php

declare(strict_types=1);

namespace App\Tests\Core\Log;

use App\Core\Log\MonologMessageLogger;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

final class MonologMessageLoggerTest extends TestCase
{
    private TestHandler $handler;
    private MonologMessageLogger $logger;

    protected function setUp(): void
    {
        $this->handler = new TestHandler();
        $monolog = new Logger('studio_message');
        $monolog->pushHandler($this->handler);
        $this->logger = new MonologMessageLogger($monolog);
    }

    public function testItWritesMessagesToMonologWithStructuredContext(): void
    {
        $error = Message::error(
            MessageCode::PROCESS_COMMAND_FAILED,
            MessageKey::PROCESS_COMMAND_FAILED,
            ['%command%' => 'bin/console demo'],
            ['exit_code' => 1, 'database_password' => 'secret'],
        );
        $message = Message::info(
            MessageCode::PACKAGE_DISCOVERY_COMPLETED,
            MessageKey::PACKAGE_DISCOVERY_COMPLETED,
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
        $this->logger->log(Message::success(MessageKey::PACKAGE_DISCOVERY_COMPLETED));
        $this->logger->log(Message::exception(
            MessageCode::OPERATION_EXCEPTION,
            MessageKey::OPERATION_EXCEPTION,
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
        $this->logger->log(Message::info(MessageCode::SETUP_LANGUAGE_SELECTED, MessageKey::SETUP_LANGUAGE_SELECTED, [
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
        $message = Message::info(MessageCode::PACKAGE_DISCOVERY_COMPLETED, MessageKey::PACKAGE_DISCOVERY_COMPLETED, [
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
}
