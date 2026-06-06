<?php

declare(strict_types=1);

namespace App\Tests\Content\Routing;

use App\Content\ContentMessageKey;
use App\Content\Routing\ContentSlug;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContentSlugTest extends TestCase
{
    public function testItAcceptsLowercaseAsciiSlugs(): void
    {
        $slug = ContentSlug::fromString('project-42');

        self::assertSame('project-42', $slug->value());
        self::assertSame('project-42', (string) $slug);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSlugs(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['Project'];
        yield 'underscore' => ['project_page'];
        yield 'leading hyphen' => ['-project'];
        yield 'trailing hyphen' => ['project-'];
        yield 'double hyphen' => ['project--page'];
        yield 'space' => ['project page'];
        yield 'umlaut' => ['über'];
    }

    #[DataProvider('invalidSlugs')]
    public function testItRejectsUnsupportedSlugShapes(string $slug): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(ContentMessageKey::CONTENT_SLUG_INVALID);

        ContentSlug::fromString($slug);
    }
}
