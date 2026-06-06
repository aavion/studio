<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Core\Log\MessageLoggerInterface;
use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Entity\AccountToken;
use App\Mail\AccountMailFlow;
use App\Mail\MailFlowRegistry;
use App\Security\AccountTokenType;
use App\Security\MessageLogAccountLinkDelivery;
use App\Security\SecurityMessageCode;
use PHPUnit\Framework\TestCase;

final class MessageLogAccountLinkDeliveryTest extends TestCase
{
    public function testItLogsStableMailFlowContextForLinks(): void
    {
        $logger = new RecordingAccountLinkMessageLogger();
        $delivery = new MessageLogAccountLinkDelivery($logger, new MailFlowRegistry());
        $token = new AccountToken(
            '55555555-5555-7555-8555-555555555555',
            hash('sha256', 'plain-account-token'),
            AccountTokenType::Registration,
            'User@Example.Test',
            ['launch_team'],
        );

        $delivery->deliver($token, AccountMailFlow::RegistrationLink, 'plain-account-token', '/user/invitation/plain-account-token', 'de');

        self::assertCount(1, $logger->records);
        self::assertSame(SecurityMessageCode::ACCOUNT_MAIL_STUB_QUEUED, $logger->records[0]['message']->code());
        self::assertSame(MessageLevel::Debug, $logger->records[0]['message']->level());
        self::assertSame(AccountMailFlow::RegistrationLink->value, $logger->records[0]['context']['mail_flow_key']);
        self::assertSame(AccountMailFlow::RegistrationLink->value, $logger->records[0]['context']['mail_template_key']);
        self::assertSame('user@example.test', $logger->records[0]['context']['recipient_email']);
        self::assertSame('de', $logger->records[0]['context']['locale']);
        self::assertSame('/user/invitation/plain-account-token', $logger->records[0]['context']['action_url']);
        self::assertSame('plain-account-token', $logger->records[0]['context']['debug_plain_token']);
        self::assertSame('/user/invitation/plain-account-token', $logger->records[0]['context']['parameters']['action_url']);
        self::assertSame('user@example.test', $logger->records[0]['context']['parameters']['email']);
        self::assertContains('expires_at', $logger->records[0]['context']['available_parameters']);
    }

    public function testItLogsStableMailFlowContextForNotifications(): void
    {
        $logger = new RecordingAccountLinkMessageLogger();
        $delivery = new MessageLogAccountLinkDelivery($logger, new MailFlowRegistry());
        $token = new AccountToken(
            '55555555-5555-7555-8555-555555555555',
            hash('sha256', 'plain-account-token'),
            AccountTokenType::Registration,
            'User@Example.Test',
            ['launch_team'],
        );

        $delivery->notify($token, AccountMailFlow::RegistrationApprovalRequested, 'admin@example.test', 'en');

        self::assertCount(1, $logger->records);
        self::assertSame(SecurityMessageCode::ACCOUNT_MAIL_STUB_QUEUED, $logger->records[0]['message']->code());
        self::assertSame(AccountMailFlow::RegistrationApprovalRequested->value, $logger->records[0]['context']['mail_flow_key']);
        self::assertSame('admin@example.test', $logger->records[0]['context']['recipient_email']);
        self::assertSame('en', $logger->records[0]['context']['locale']);
        self::assertSame('user@example.test', $logger->records[0]['context']['parameters']['email']);
        self::assertTrue($logger->records[0]['context']['recipient_configured']);
    }

    public function testItLogsStableMailFlowContextForAddressNotifications(): void
    {
        $logger = new RecordingAccountLinkMessageLogger();
        $delivery = new MessageLogAccountLinkDelivery($logger, new MailFlowRegistry());

        $delivery->notifyAddress('User@Example.Test', AccountMailFlow::RegistrationExistingAccount, 'en', [
            'username' => 'existing_user',
        ]);

        self::assertCount(1, $logger->records);
        self::assertSame(SecurityMessageCode::ACCOUNT_MAIL_STUB_QUEUED, $logger->records[0]['message']->code());
        self::assertSame(AccountMailFlow::RegistrationExistingAccount->value, $logger->records[0]['context']['mail_flow_key']);
        self::assertSame('user@example.test', $logger->records[0]['context']['recipient_email']);
        self::assertSame('existing_user', $logger->records[0]['context']['parameters']['username']);
        self::assertSame('user@example.test', $logger->records[0]['context']['parameters']['email']);
    }
}

final class RecordingAccountLinkMessageLogger implements MessageLoggerInterface
{
    /**
     * @var list<array{message: Message, context: array<string, mixed>}>
     */
    public array $records = [];

    public function log(Message $message, array $context = []): void
    {
        $this->records[] = [
            'message' => $message,
            'context' => $context,
        ];
    }

    public function logBatch(iterable $records): void
    {
        foreach ($records as $record) {
            $this->log($record['message'], $record['context'] ?? []);
        }
    }
}
