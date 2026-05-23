<?php

declare(strict_types=1);

namespace App\Tests\Core\Workflow;

use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Workflow\OperationIssue;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OperationIssueTest extends TestCase
{
    public function testItStoresCodeMessageAndContext(): void
    {
        $issue = OperationIssue::create(MessageCode::MANIFEST_MISSING_REQUIRED_KEY, MessageKey::MANIFEST_MISSING_REQUIRED_KEY, [
            '%key%' => 'MODULE_NAME',
        ], [
            'key' => 'MODULE_NAME',
        ]);

        self::assertSame(MessageCode::MANIFEST_MISSING_REQUIRED_KEY, $issue->code());
        self::assertSame(MessageKey::MANIFEST_MISSING_REQUIRED_KEY, $issue->translationKey());
        self::assertSame(MessageKey::MANIFEST_MISSING_REQUIRED_KEY, $issue->message()->translationKey());
        self::assertSame(['%key%' => 'MODULE_NAME'], $issue->parameters());
        self::assertSame(['key' => 'MODULE_NAME'], $issue->context());
    }

    public function testItCanBeCreatedFromAMessage(): void
    {
        $message = Message::create(
            MessageCode::E_INVALID_ARGUMENT,
            MessageKey::CONTENT_SLUG_INVALID,
            ['%slug%' => 'Invalid Slug'],
            ['field' => 'slug'],
        );

        $issue = OperationIssue::fromMessage($message);

        self::assertSame(MessageCode::E_INVALID_ARGUMENT, $issue->code());
        self::assertSame(MessageKey::CONTENT_SLUG_INVALID, $issue->translationKey());
        self::assertSame(['%slug%' => 'Invalid Slug'], $issue->parameters());
        self::assertSame(['field' => 'slug'], $issue->context());
    }

    public function testItRejectsEmptyCodes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid message code " ".');

        OperationIssue::create(' ', MessageKey::MANIFEST_MISSING_REQUIRED_KEY);
    }

    public function testItRejectsEmptyMessages(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid message translation key " ".');

        OperationIssue::create(MessageCode::MANIFEST_MISSING_REQUIRED_KEY, ' ');
    }
}
