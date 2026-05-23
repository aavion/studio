<?php

declare(strict_types=1);

namespace App\Tests\Core\Message;

use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MessageTest extends TestCase
{
    public function testItCarriesCodeTranslationKeyParametersAndContext(): void
    {
        $message = Message::create(
            MessageCode::E_INVALID_ARGUMENT,
            MessageKey::CONTENT_SLUG_INVALID,
            ['%slug%' => 'Invalid Slug'],
            ['field' => 'slug'],
        );

        self::assertSame(MessageCode::E_INVALID_ARGUMENT, $message->code());
        self::assertSame(MessageKey::CONTENT_SLUG_INVALID, $message->translationKey());
        self::assertSame(['%slug%' => 'Invalid Slug'], $message->parameters());
        self::assertSame(['field' => 'slug'], $message->context());
        self::assertSame([
            'code' => MessageCode::E_INVALID_ARGUMENT,
            'translation_key' => MessageKey::CONTENT_SLUG_INVALID,
            'parameters' => ['%slug%' => 'Invalid Slug'],
            'context' => ['field' => 'slug'],
        ], $message->toArray());
    }

    public function testItCreatesSuccessMessages(): void
    {
        $message = Message::success('message.content.entity_saved');

        self::assertSame(MessageCode::SUCCESS, $message->code());
        self::assertSame('message.content.entity_saved', $message->translationKey());
    }

    public function testItCanMergeContext(): void
    {
        $message = Message::create(MessageCode::E_OPERATION_FAILED, MessageKey::OPERATION_EXCEPTION, context: [
            'source' => 'core',
        ])->withContext([
            'source' => 'module',
            'module' => 'demo',
        ]);

        self::assertSame(['source' => 'module', 'module' => 'demo'], $message->context());
    }

    public function testItRejectsEmptyCodes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid message code " ".');

        Message::create(' ', MessageKey::CONTENT_SLUG_INVALID);
    }

    public function testItRejectsEmptyTranslationKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid message translation key " ".');

        Message::create(MessageCode::E_INVALID_ARGUMENT, ' ');
    }

    public function testItRejectsInvalidParameterKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid message parameter key "slug".');

        Message::create(MessageCode::E_INVALID_ARGUMENT, MessageKey::CONTENT_SLUG_INVALID, [
            'slug' => 'Invalid Slug',
        ]);
    }
}
