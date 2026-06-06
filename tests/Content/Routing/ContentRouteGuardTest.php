<?php

declare(strict_types=1);

namespace App\Tests\Content\Routing;

use App\Content\ContentMessageKey;
use App\Content\Routing\ContentRouteGuard;
use App\Core\Message\MessageException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ContentRouteGuardTest extends TestCase
{
    public function testItRejectsReservedSlugs(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(ContentMessageKey::CONTENT_SLUG_RESERVED);

        (new ContentRouteGuard())->assertSlugAllowed('system');
    }

    public function testItNormalizesAndAcceptsContentPaths(): void
    {
        $path = (new ContentRouteGuard())->assertPathAllowed('projects/demo/~compact');

        self::assertSame('/projects/demo/~compact', $path);
    }

    public function testItRejectsVariantMarkersBeforeTheLastPathSegment(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(ContentMessageKey::CONTENT_PATH_VARIANT_INVALID);

        (new ContentRouteGuard())->assertPathAllowed('/projects/~compact/demo');
    }

    public function testItRejectsReservedPathPrefixes(): void
    {
        foreach (['system', 'user', 'setup', 'cron', 'admin', 'editor', 'packages'] as $prefix) {
            try {
                (new ContentRouteGuard())->assertPathAllowed(sprintf('/%s/example', $prefix));
                self::fail(sprintf('Expected prefix "%s" to be reserved.', $prefix));
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString(ContentMessageKey::CONTENT_PATH_RESERVED_PREFIX, $exception->getMessage());
            }
        }
    }

    public function testItRejectsReservedApiPathPrefix(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(ContentMessageKey::CONTENT_PATH_RESERVED_PREFIX);

        (new ContentRouteGuard())->assertPathAllowed('/api/articles');
    }

    public function testItRejectsTraversalSegmentsWithTranslationParameters(): void
    {
        try {
            (new ContentRouteGuard())->assertPathAllowed('/projects/../secret');
            self::fail('Expected content validation to reject traversal segments.');
        } catch (MessageException $exception) {
            self::assertSame(ContentMessageKey::CONTENT_PATH_TRAVERSAL, $exception->messageKey());
            self::assertSame([
                '%path%' => '/projects/../secret',
                '%segment%' => '..',
            ], $exception->parameters());
        }
    }
}
