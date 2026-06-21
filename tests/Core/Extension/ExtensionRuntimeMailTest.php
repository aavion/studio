<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Extension\ExtensionMailFacade;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;
use App\Core\Extension\ExtensionRuntime;
use App\Core\Extension\ExtensionRuntimeServices;
use App\Core\Log\MessageLoggerInterface;
use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Tests\Support\FilesystemTestHelper;
use PHPUnit\Framework\TestCase;

final class ExtensionRuntimeMailTest extends TestCase
{
    use FilesystemTestHelper;

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = dirname(__DIR__, 3);
        $this->removeDirectory($this->projectDir.'/extensions/mail-facade');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir.'/extensions/mail-facade');
        ExtensionRuntime::reset();
    }

    public function testItLogsCallerOwnedMailStubMessages(): void
    {
        $logger = new RecordingExtensionMailLogger();
        ExtensionRuntime::configure(new ExtensionRuntimeServices($this->projectDir, mail: new ExtensionMailFacade($logger)));
        $this->writeExtensionFile(<<<'PHP'
            <?php

            return extension_mail(
                'mail-facade.registration_notice',
                ['USER@Example.test', 'user@example.test'],
                ['username' => 'letica', 'count' => 2],
                ['locale' => 'de']
            );
            PHP);

        self::assertTrue(require $this->projectDir.'/extensions/mail-facade/extension.php');
        self::assertCount(1, $logger->messages);

        $message = $logger->messages[0];
        self::assertSame(MessageLevel::Debug, $message->level());
        self::assertSame(ExtensionMessageCode::EXTENSION_RUNTIME_MAIL_STUB, $message->code());
        self::assertSame(ExtensionMessageKey::EXTENSION_RUNTIME_MAIL_STUB, $message->translationKey());
        self::assertSame('mail-facade', $message->context()['extension']);
        self::assertSame('mail-facade.registration_notice', $message->context()['mail_workflow']);
        self::assertSame(['user@example.test'], $message->context()['recipient_emails']);
        self::assertSame(['count' => '2', 'username' => 'letica'], $message->context()['parameters']);
        self::assertSame('de', $message->context()['locale']);
        self::assertTrue($message->context()['stub']);
    }

    public function testItRejectsForeignWorkflowsInvalidPayloadsAndNonExtensionCallers(): void
    {
        $logger = new RecordingExtensionMailLogger();
        ExtensionRuntime::configure(new ExtensionRuntimeServices($this->projectDir, mail: new ExtensionMailFacade($logger)));

        self::assertFalse(ExtensionRuntime::mail('mail-facade.registration_notice', 'user@example.test'));

        $this->writeExtensionFile(<<<'PHP'
            <?php

            return [
                extension_mail('other.registration_notice', 'user@example.test'),
                extension_mail('mail-facade.registration_notice', 'not-an-email'),
                extension_mail('mail-facade.registration_notice', 'user@example.test', ['bad-key' => 'value']),
                extension_mail('mail-facade.registration_notice', 'user@example.test', ['payload' => new stdClass()]),
                extension_mail('mail-facade.registration_notice', 'user@example.test', ['payload' => str_repeat('x', 4097)]),
                extension_mail('mail-facade.registration_notice', []),
            ];
            PHP);

        self::assertSame(
            [false, false, false, false, false, false],
            require $this->projectDir.'/extensions/mail-facade/extension.php',
        );
        self::assertSame([], $logger->messages);
    }

    private function writeExtensionFile(string $contents): void
    {
        $this->writeTestFile($this->projectDir, 'extensions/mail-facade/extension.php', $contents);
    }
}

final class RecordingExtensionMailLogger implements MessageLoggerInterface
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
