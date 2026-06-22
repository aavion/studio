<?php

declare(strict_types=1);

namespace App\Tests\Core\Message;

use App\Content\ContentMessageKey;
use App\Core\Message\CommonMessageCode;
use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Operation\OperationMessageKey;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MessageTest extends TestCase
{
    public function testItCarriesCodeTranslationKeyParametersAndContext(): void
    {
        $message = Message::create(
            CommonMessageCode::E_INVALID_ARGUMENT,
            ContentMessageKey::CONTENT_SLUG_INVALID,
            ['%slug%' => 'Invalid Slug'],
            ['field' => 'slug'],
        );

        self::assertSame(CommonMessageCode::E_INVALID_ARGUMENT, $message->code());
        self::assertSame(ContentMessageKey::CONTENT_SLUG_INVALID, $message->translationKey());
        self::assertSame(MessageLevel::Warning, $message->level());
        self::assertSame(['%slug%' => 'Invalid Slug'], $message->parameters());
        self::assertSame(['field' => 'slug'], $message->context());
        self::assertSame([
            'level' => MessageLevel::Warning->value,
            'code' => CommonMessageCode::E_INVALID_ARGUMENT,
            'translation_key' => ContentMessageKey::CONTENT_SLUG_INVALID,
            'parameters' => ['%slug%' => 'Invalid Slug'],
            'context' => ['field' => 'slug'],
        ], $message->toArray());
    }

    public function testItCreatesSuccessMessages(): void
    {
        $message = Message::success('message.content.entity_saved');

        self::assertSame(CommonMessageCode::SUCCESS, $message->code());
        self::assertSame('message.content.entity_saved', $message->translationKey());
        self::assertSame(MessageLevel::Success, $message->level());
    }

    public function testItAcceptsExtensionOwnedTranslationKeys(): void
    {
        $message = Message::info('extension.runtime.log', 'ext.demo-module.runtime.ready');

        self::assertSame('ext.demo-module.runtime.ready', $message->translationKey());
        self::assertSame(MessageLevel::Info, $message->level());
    }

    public function testItCreatesInvalidArgumentMessages(): void
    {
        $message = Message::invalidArgument(ContentMessageKey::CONTENT_SLUG_INVALID, [
            '%slug%' => 'Invalid Slug',
        ]);

        self::assertSame(CommonMessageCode::E_INVALID_ARGUMENT, $message->code());
        self::assertSame(ContentMessageKey::CONTENT_SLUG_INVALID, $message->translationKey());
        self::assertSame(MessageLevel::Warning, $message->level());
        self::assertSame(['%slug%' => 'Invalid Slug'], $message->parameters());
    }

    public function testItCreatesExplicitLogLevelMessages(): void
    {
        self::assertSame(MessageLevel::Error, Message::error('custom.failed', OperationMessageKey::OPERATION_EXCEPTION)->level());
        self::assertSame(MessageLevel::Exception, Message::exception('custom.exception', OperationMessageKey::OPERATION_EXCEPTION)->level());
        self::assertSame(MessageLevel::Warning, Message::warning('custom.warning', OperationMessageKey::OPERATION_EXCEPTION)->level());
        self::assertSame(MessageLevel::Info, Message::info('custom.info', OperationMessageKey::OPERATION_EXCEPTION)->level());
        self::assertSame(MessageLevel::Debug, Message::debug('custom.debug', OperationMessageKey::OPERATION_EXCEPTION)->level());
    }

    public function testItCanMergeContext(): void
    {
        $message = Message::create(CommonMessageCode::E_OPERATION_FAILED, OperationMessageKey::OPERATION_EXCEPTION, context: [
            'source' => 'core',
        ])->withContext([
            'source' => 'module',
            'module' => 'demo',
        ]);

        self::assertSame(['source' => 'module', 'module' => 'demo'], $message->context());
        self::assertSame(MessageLevel::Error, $message->level());
    }

    public function testItRejectsEmptyCodes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid message code " ".');

        Message::create(' ', ContentMessageKey::CONTENT_SLUG_INVALID);
    }

    public function testItRejectsEmptyTranslationKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid message translation key " ".');

        Message::create(CommonMessageCode::E_INVALID_ARGUMENT, ' ');
    }

    public function testItRejectsInvalidParameterKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid message parameter key "slug".');

        Message::create(CommonMessageCode::E_INVALID_ARGUMENT, ContentMessageKey::CONTENT_SLUG_INVALID, [
            'slug' => 'Invalid Slug',
        ]);
    }
}
