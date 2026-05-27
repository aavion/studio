<?php

declare(strict_types=1);

namespace App\Tests\Core\Log;

use App\Core\Log\FileMessageLogger;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Tests\Support\FilesystemTestHelper;
use PHPUnit\Framework\TestCase;

final class FileMessageLoggerTest extends TestCase
{
    use FilesystemTestHelper;

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = $this->createTemporaryDirectory('studio-message-logger');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    public function testItWritesMessagesToEnvironmentLog(): void
    {
        $logger = new FileMessageLogger($this->projectDir, 'test');
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

        $logger->logBatch([
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

        $contents = (string) file_get_contents($this->projectDir.'/var/log/test/operations.log');

        self::assertMatchesRegularExpression('/^\[[^\]]+\] \[ERROR\] message\.process\.command_failed/m', $contents);
        self::assertStringContainsString('[INFO] message.package.discovery_completed', $contents);
        self::assertStringContainsString('"code":"process.command_failed"', $contents);
        self::assertStringContainsString('"queue":"demo"', $contents);
        self::assertStringContainsString('"database_password":"[redacted]"', $contents);
        self::assertStringContainsString('"api_token":"[redacted]"', $contents);
        self::assertStringContainsString('"app_secret":"[redacted]"', $contents);
        self::assertStringNotContainsString('"database_password":"secret"', $contents);
        self::assertStringNotContainsString('"api_token":"abc123"', $contents);
        self::assertStringNotContainsString('"app_secret":"hidden"', $contents);
    }

    public function testItWritesExceptionLevelMessages(): void
    {
        $logger = new FileMessageLogger($this->projectDir, 'test');

        $logger->log(Message::exception(
            MessageCode::OPERATION_EXCEPTION,
            MessageKey::OPERATION_EXCEPTION,
            context: ['exception' => 'RuntimeException'],
        ));

        $contents = (string) file_get_contents($this->projectDir.'/var/log/test/operations.log');

        self::assertStringContainsString('[EXCEPTION] message.operation.exception', $contents);
    }

    public function testItDoesNotCreateALogFileForEmptyBatches(): void
    {
        $logger = new FileMessageLogger($this->projectDir, 'test');

        $logger->logBatch([]);

        self::assertFileDoesNotExist($this->projectDir.'/var/log/test/operations.log');
    }

    public function testItLogsSingleMessages(): void
    {
        $logger = new FileMessageLogger($this->projectDir, 'test');
        $message = Message::info(MessageCode::SETUP_LANGUAGE_SELECTED, MessageKey::SETUP_LANGUAGE_SELECTED, [
            '%language%' => 'en',
        ]);

        $logger->log($message, [
            'operation' => 'setup.run',
            'token' => 'secret',
        ]);

        $contents = (string) file_get_contents($this->projectDir.'/var/log/test/operations.log');

        self::assertStringContainsString('[INFO] message.setup.language_selected', $contents);
        self::assertStringContainsString('"token":"[redacted]"', $contents);
    }

    public function testItDeduplicatesIdenticalMessageEntries(): void
    {
        $logger = new FileMessageLogger($this->projectDir, 'test');
        $message = Message::info(MessageCode::PACKAGE_DISCOVERY_COMPLETED, MessageKey::PACKAGE_DISCOVERY_COMPLETED, [
            '%count%' => 1,
        ]);

        $logger->logBatch([
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

        $contents = (string) file_get_contents($this->projectDir.'/var/log/test/operations.log');

        self::assertSame(1, substr_count($contents, 'message.package.discovery_completed'));
    }
}
