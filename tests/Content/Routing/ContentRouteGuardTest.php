<?php

declare(strict_types=1);

namespace App\Tests\Content\Routing;

use App\Content\Routing\ContentRouteGuard;
use App\Core\Message\MessageException;
use App\Core\Message\MessageKey;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ContentRouteGuardTest extends TestCase
{
    public function testItRejectsReservedSlugs(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(MessageKey::CONTENT_SLUG_RESERVED);

        (new ContentRouteGuard())->assertSlugAllowed('admin');
    }

    public function testItNormalizesAndAcceptsContentPaths(): void
    {
        $path = (new ContentRouteGuard())->assertPathAllowed('projects/demo/~compact');

        self::assertSame('/projects/demo/~compact', $path);
    }

    public function testItRejectsReservedPathPrefixes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(MessageKey::CONTENT_PATH_RESERVED_PREFIX);

        (new ContentRouteGuard())->assertPathAllowed('/api/articles');
    }

    public function testItRejectsTraversalSegmentsWithTranslationParameters(): void
    {
        try {
            (new ContentRouteGuard())->assertPathAllowed('/projects/../secret');
            self::fail('Expected content validation to reject traversal segments.');
        } catch (MessageException $exception) {
            self::assertSame(MessageKey::CONTENT_PATH_TRAVERSAL, $exception->messageKey());
            self::assertSame([
                '%path%' => '/projects/../secret',
                '%segment%' => '..',
            ], $exception->parameters());
        }
    }
}
