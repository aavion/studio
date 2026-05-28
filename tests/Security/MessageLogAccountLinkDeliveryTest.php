<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Core\Log\MessageLoggerInterface;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Entity\AccountToken;
use App\Security\AccountMailFlow;
use App\Security\AccountTokenType;
use App\Security\MessageLogAccountLinkDelivery;
use PHPUnit\Framework\TestCase;

final class MessageLogAccountLinkDeliveryTest extends TestCase
{
    public function testItLogsStableMailFlowContextForLinks(): void
    {
        $logger = new RecordingAccountLinkMessageLogger();
        $delivery = new MessageLogAccountLinkDelivery($logger);
        $token = new AccountToken(
            '55555555-5555-4555-8555-555555555555',
            hash('sha256', 'plain-account-token'),
            AccountTokenType::Registration,
            'User@Example.Test',
            ['registered'],
        );

        $delivery->deliver($token, AccountMailFlow::RegistrationLink, 'plain-account-token', '/user/invitation/plain-account-token');

        self::assertCount(1, $logger->records);
        self::assertSame(MessageCode::ACCOUNT_LINK_DELIVERED, $logger->records[0]['message']->code());
        self::assertSame(AccountMailFlow::RegistrationLink->value, $logger->records[0]['context']['mail_flow_key']);
        self::assertSame('user@example.test', $logger->records[0]['context']['recipient_email']);
        self::assertSame('/user/invitation/plain-account-token', $logger->records[0]['context']['account_link']);
    }

    public function testItLogsStableMailFlowContextForNotifications(): void
    {
        $logger = new RecordingAccountLinkMessageLogger();
        $delivery = new MessageLogAccountLinkDelivery($logger);
        $token = new AccountToken(
            '55555555-5555-4555-8555-555555555555',
            hash('sha256', 'plain-account-token'),
            AccountTokenType::Registration,
            'User@Example.Test',
            ['registered'],
        );

        $delivery->notify($token, AccountMailFlow::RegistrationApprovalRequested, 'admin@example.test');

        self::assertCount(1, $logger->records);
        self::assertSame(MessageCode::ACCOUNT_NOTIFICATION_DELIVERED, $logger->records[0]['message']->code());
        self::assertSame(AccountMailFlow::RegistrationApprovalRequested->value, $logger->records[0]['context']['mail_flow_key']);
        self::assertSame('admin@example.test', $logger->records[0]['context']['recipient_email']);
        self::assertSame('user@example.test', $logger->records[0]['context']['account_email']);
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
