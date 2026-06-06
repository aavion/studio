<?php

declare(strict_types=1);

namespace App\Tests\View;

use App\View\MarkdownRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class MarkdownRendererTest extends TestCase
{
    public function testItRendersReadmeMarkdownBlocks(): void
    {
        $html = (new MarkdownRenderer())->render("# Title\n\nA **bold** link to [Studio](https://example.test).\n\n> **Version**: 1.0  \n> **Status**: Active\n\n- [x] One\n- [ ] Two\n\nSee [Docs](dev/manual/README.md).", 'readme');

        self::assertStringContainsString('<h1>Title</h1>', $html);
        self::assertStringContainsString('<strong>bold</strong>', $html);
        self::assertStringContainsString('<a href="https://example.test"', $html);
        self::assertStringContainsString('<blockquote>', $html);
        self::assertStringContainsString('<strong>Version</strong>: 1.0<br />', $html);
        self::assertStringContainsString('<ul>', $html);
        self::assertStringContainsString('type="checkbox"', $html);
        self::assertStringContainsString('One</li>', $html);
        self::assertStringContainsString('Two</li>', $html);
        self::assertStringContainsString('<a href="dev/manual/README.md"', $html);
    }

    public function testItRendersDesignMarkdownWithRichControls(): void
    {
        $markdown = implode("\n\n", [
            '# Landing Page {.hero-title}',
            '[TOC]',
            '## Section',
            '==Highlighted== text with smart quotes: "hello".',
            "Term\n: Description",
            'A footnote.[^1]',
            '[^1]: Footnote body.',
            'https://youtu.be/dQw4w9WgXcQ',
            '<aside data-demo="yes">Trusted HTML</aside>',
        ]);
        $html = (new MarkdownRenderer())->render($markdown, 'design');

        self::assertStringContainsString('<h1 class="hero-title" id="landing-page">Landing Page', $html);
        self::assertStringContainsString('href="#landing-page" class="heading-permalink"', $html);
        self::assertStringContainsString('studio-markdown-toc', $html);
        self::assertStringContainsString('<mark>Highlighted</mark>', $html);
        self::assertStringContainsString('<dl>', $html);
        self::assertStringContainsString('footnote-ref', $html);
        self::assertStringContainsString('youtube-nocookie.com/embed/dQw4w9WgXcQ', $html);
        self::assertStringContainsString('<aside data-demo="yes">Trusted HTML</aside>', $html);
    }

    public function testItRendersAllrounderMarkdownWithoutRawHtmlOrEmbeds(): void
    {
        $html = (new MarkdownRenderer())->render("<script>alert(1)</script>\n\nhttps://youtu.be/dQw4w9WgXcQ\n\n# Title {#custom-title}", 'allrounder');

        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('youtube-nocookie.com', $html);
        self::assertStringContainsString('href="https://youtu.be/dQw4w9WgXcQ"', $html);
        self::assertStringContainsString('<h1 id="title">Title', $html);
    }

    public function testItUsesTranslatedEmbedVideoTitle(): void
    {
        $translator = new class implements TranslatorInterface {
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return 'ui.markdown.embed.video_title' === $id ? 'Localized "Video"' : $id;
            }

            public function getLocale(): string
            {
                return 'en';
            }
        };

        $html = (new MarkdownRenderer($translator))->render('https://youtu.be/dQw4w9WgXcQ', 'design');

        self::assertStringContainsString('title="Localized &quot;Video&quot;"', $html);
    }

    public function testItRendersBasicMarkdownForUntrustedContent(): void
    {
        $html = (new MarkdownRenderer())->render("# Title\n\n| A | B |\n| - | - |\n| 1 | 2 |\n\n<script>alert(1)</script>", 'basic');

        self::assertStringContainsString('<h1>Title</h1>', $html);
        self::assertStringNotContainsString('<table>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testItEscapesInlineHtmlByDefault(): void
    {
        $html = (new MarkdownRenderer())->render('<script>alert(1)</script>');

        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testItRejectsUnsafeLinksAcrossProfiles(): void
    {
        $renderer = new MarkdownRenderer();

        foreach (['readme', 'design', 'allrounder', 'basic'] as $profile) {
            self::assertStringNotContainsString('javascript:alert', $renderer->render('[x](javascript:alert(1))', $profile));
        }
    }

    public function testUnknownProfileFallsBackToAllrounder(): void
    {
        $html = (new MarkdownRenderer())->render('# Title', 'missing-profile');

        self::assertStringContainsString('<h1 id="title">Title', $html);
    }
}
