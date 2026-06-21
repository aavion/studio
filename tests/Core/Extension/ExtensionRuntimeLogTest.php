<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Extension\ExtensionLogFacade;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;
use App\Core\Extension\ExtensionRuntime;
use App\Core\Extension\ExtensionRuntimeServices;
use App\Core\Log\MessageLoggerInterface;
use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Tests\Support\FilesystemTestHelper;
use PHPUnit\Framework\TestCase;

final class ExtensionRuntimeLogTest extends TestCase
{
    use FilesystemTestHelper;

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = dirname(__DIR__, 3);
        $this->removeDirectory($this->projectDir.'/extensions/log-facade');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir.'/extensions/log-facade');
        ExtensionRuntime::reset();
    }

    public function testItLogsCallerOwnedMessages(): void
    {
        $logger = new RecordingExtensionLogger();
        ExtensionRuntime::configure(new ExtensionRuntimeServices($this->projectDir, logs: new ExtensionLogFacade($logger)));
        $this->writeExtensionFile(<<<'PHP'
            <?php

            return extension_log('info', 'ext.log-facade.runtime.ready', [
                'extension' => 'spoofed',
                'detail' => 'ready',
            ]);
            PHP);

        self::assertTrue(require $this->projectDir.'/extensions/log-facade/extension.php');
        self::assertCount(1, $logger->messages);

        $message = $logger->messages[0];
        self::assertSame(MessageLevel::Info, $message->level());
        self::assertSame(ExtensionMessageCode::EXTENSION_RUNTIME_LOG, $message->code());
        self::assertSame('ext.log-facade.runtime.ready', $message->translationKey());
        self::assertSame('log-facade', $message->context()['extension']);
        self::assertSame('extension_runtime', $message->context()['source']);
        self::assertSame('spoofed', $message->context()['extension_context']['extension']);
        self::assertSame('ready', $message->context()['extension_context']['detail']);
    }

    public function testItFallsBackForLiteralMessagesAndRejectsInvalidCallersOrLevels(): void
    {
        $logger = new RecordingExtensionLogger();
        ExtensionRuntime::configure(new ExtensionRuntimeServices($this->projectDir, logs: new ExtensionLogFacade($logger)));
        self::assertFalse(ExtensionRuntime::log('info', 'message.extension.discovery_completed'));

        $this->writeExtensionFile(<<<'PHP'
            <?php

            return [
                extension_log('verbose', 'ext.log-facade.runtime.ready'),
                extension_log('warning', 'Provider finished'),
                extension_log('info', 'ext.other.runtime.ready'),
                extension_log('info', 'message.extension.discovery_completed'),
            ];
            PHP);

        self::assertSame([false, true, true, true], require $this->projectDir.'/extensions/log-facade/extension.php');
        self::assertCount(3, $logger->messages);

        $message = $logger->messages[0];
        self::assertSame(MessageLevel::Warning, $message->level());
        self::assertSame(ExtensionMessageKey::EXTENSION_RUNTIME_LOG, $message->translationKey());
        self::assertSame(['%message%' => 'Provider finished'], $message->parameters());
        self::assertSame(ExtensionMessageKey::EXTENSION_RUNTIME_LOG, $logger->messages[1]->translationKey());
        self::assertSame(['%message%' => 'ext.other.runtime.ready'], $logger->messages[1]->parameters());
        self::assertSame(ExtensionMessageKey::EXTENSION_RUNTIME_LOG, $logger->messages[2]->translationKey());
        self::assertSame(['%message%' => 'message.extension.discovery_completed'], $logger->messages[2]->parameters());
    }

    private function writeExtensionFile(string $contents): void
    {
        $this->writeTestFile($this->projectDir, 'extensions/log-facade/extension.php', $contents);
    }
}

final class RecordingExtensionLogger implements MessageLoggerInterface
{
    /**
     * @var list<Message>
     */
    public array $messages = [];

    public function log(Message $message, array $context = []): void
    {
        $this->messages[] = [] === $context ? $message : $message->withContext($context);
    }

    public function logBatch(iterable $records): void
    {
        foreach ($records as $record) {
            $this->log($record['message'], $record['context'] ?? []);
        }
    }
}
