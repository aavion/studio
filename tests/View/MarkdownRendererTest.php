<?php

declare(strict_types=1);

namespace App\Tests\View;

use App\View\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

final class MarkdownRendererTest extends TestCase
{
    public function testItRendersSafeMarkdownBlocks(): void
    {
        $html = (new MarkdownRenderer())->render("# Title\n\nA **bold** link to [Studio](https://example.test).\n\n- One\n- Two");

        self::assertStringContainsString('<h1>Title</h1>', $html);
        self::assertStringContainsString('<strong>bold</strong>', $html);
        self::assertStringContainsString('<a href="https://example.test"', $html);
        self::assertStringContainsString('<ul><li>One</li><li>Two</li></ul>', $html);
    }

    public function testItEscapesInlineHtml(): void
    {
        $html = (new MarkdownRenderer())->render('<script>alert(1)</script>');

        self::assertSame('<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>', $html);
    }
}
