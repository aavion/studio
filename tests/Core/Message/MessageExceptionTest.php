<?php

declare(strict_types=1);

namespace App\Tests\Core\Message;

use App\Content\ContentMessageKey;
use App\Core\Message\CommonMessageCode;
use App\Core\Message\Message;
use App\Core\Message\MessageException;
use App\Core\Message\MessageLevel;
use App\Core\Package\PackageMessageKey;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MessageExceptionTest extends TestCase
{
    public function testItCarriesMessageKeyAndParameters(): void
    {
        $exception = MessageException::invalidArgument(ContentMessageKey::CONTENT_SLUG_INVALID, [
            '%slug%' => 'Invalid Slug',
        ], [
            'field' => 'slug',
        ]);

        self::assertInstanceOf(InvalidArgumentException::class, $exception);
        self::assertSame(ContentMessageKey::CONTENT_SLUG_INVALID, $exception->getMessage());
        self::assertSame(CommonMessageCode::E_INVALID_ARGUMENT, $exception->code());
        self::assertSame(ContentMessageKey::CONTENT_SLUG_INVALID, $exception->messageKey());
        self::assertSame(MessageLevel::Warning, $exception->level());
        self::assertSame(['%slug%' => 'Invalid Slug'], $exception->parameters());
        self::assertSame(['field' => 'slug'], $exception->context());
    }

    public function testItCanBeCreatedFromAMessage(): void
    {
        $message = Message::success(PackageMessageKey::PACKAGE_REQUIRED_FILE_MISSING);
        $exception = MessageException::fromMessage($message);

        self::assertSame($message, $exception->message());
        self::assertSame(CommonMessageCode::SUCCESS, $exception->code());
    }
}
