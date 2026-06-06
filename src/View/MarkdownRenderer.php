<?php

declare(strict_types=1);

namespace App\View;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Attributes\AttributesExtension;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\DescriptionList\DescriptionListExtension;
use League\CommonMark\Extension\DisallowedRawHtml\DisallowedRawHtmlExtension;
use League\CommonMark\Extension\Embed\EmbedExtension;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\Extension\ExternalLink\ExternalLinkProcessor;
use League\CommonMark\Extension\Footnote\FootnoteExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkProcessor;
use League\CommonMark\Extension\Highlight\HighlightExtension;
use League\CommonMark\Extension\SmartPunct\SmartPunctExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\TableOfContents\TableOfContentsBuilder;
use League\CommonMark\Extension\TableOfContents\TableOfContentsExtension;
use League\CommonMark\Extension\TaskList\TaskListExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Output\RenderedContentInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class MarkdownRenderer
{
    private const EMBED_VIDEO_TITLE_KEY = 'ui.markdown.embed.video_title';

    /**
     * @var array<string, MarkdownConverter>
     */
    private array $converters = [];

    public function __construct(private readonly ?TranslatorInterface $translator = null)
    {
    }

    public function render(string $markdown, string|MarkdownProfile|null $profile = null): string
    {
        if ('' === trim($markdown)) {
            return '';
        }

        $rendered = $this->converter(MarkdownProfile::resolve($profile))->convert($markdown);

        $html = $rendered instanceof RenderedContentInterface ? $rendered->getContent() : (string) $rendered;

        return rtrim($html);
    }

    private function converter(MarkdownProfile $profile): MarkdownConverter
    {
        return $this->converters[$profile->value] ??= match ($profile) {
            MarkdownProfile::Readme => $this->readmeConverter(),
            MarkdownProfile::Design => $this->designConverter(),
            MarkdownProfile::Allrounder => $this->allrounderConverter(),
            MarkdownProfile::Basic => $this->basicConverter(),
        };
    }

    private function readmeConverter(): MarkdownConverter
    {
        $environment = new Environment($this->safeConfig());
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());

        return new MarkdownConverter($environment);
    }

    private function designConverter(): MarkdownConverter
    {
        $environment = new Environment($this->richConfig([
            'allow_unsafe_links' => false,
            'html_input' => 'allow',
            'attributes' => [
                'allow' => [
                    'class',
                    'id',
                    'style',
                    'title',
                    'aria-label',
                    'aria-hidden',
                    'data-*',
                ],
            ],
        ]));

        $this->addRichExtensions($environment);
        $environment->addExtension(new AttributesExtension());
        $environment->addExtension(new EmbedExtension());

        return new MarkdownConverter($environment);
    }

    private function allrounderConverter(): MarkdownConverter
    {
        $environment = new Environment($this->richConfig([
            'allow_unsafe_links' => false,
            'html_input' => 'escape',
            'attributes' => [
                'allow' => [
                    'class',
                    'id',
                    'title',
                    'aria-label',
                    'aria-hidden',
                    'data-*',
                ],
            ],
        ]));

        $this->addRichExtensions($environment);
        $environment->addExtension(new AttributesExtension());

        return new MarkdownConverter($environment);
    }

    private function basicConverter(): MarkdownConverter
    {
        $environment = new Environment($this->safeConfig());
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new AutolinkExtension());
        $environment->addExtension(new DisallowedRawHtmlExtension());

        return new MarkdownConverter($environment);
    }

    private function addRichExtensions(Environment $environment): void
    {
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new AutolinkExtension());
        $environment->addExtension(new DisallowedRawHtmlExtension());
        $environment->addExtension(new DescriptionListExtension());
        $environment->addExtension(new ExternalLinkExtension());
        $environment->addExtension(new FootnoteExtension());
        $environment->addExtension(new HeadingPermalinkExtension());
        $environment->addExtension(new HighlightExtension());
        $environment->addExtension(new SmartPunctExtension());
        $environment->addExtension(new StrikethroughExtension());
        $environment->addExtension(new TableExtension());
        $environment->addExtension(new TableOfContentsExtension());
        $environment->addExtension(new TaskListExtension());
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function richConfig(array $overrides = []): array
    {
        return array_replace_recursive($this->safeConfig(), [
            'embed' => [
                'adapter' => new MarkdownEmbedAdapter($this->embedVideoTitle()),
                'allowed_domains' => [
                    'youtube.com',
                    'www.youtube.com',
                    'm.youtube.com',
                    'youtu.be',
                    'www.youtu.be',
                ],
                'fallback' => 'link',
            ],
            'external_link' => [
                'internal_hosts' => [],
                'open_in_new_window' => true,
                'nofollow' => ExternalLinkProcessor::APPLY_NONE,
                'noopener' => ExternalLinkProcessor::APPLY_EXTERNAL,
                'noreferrer' => ExternalLinkProcessor::APPLY_EXTERNAL,
            ],
            'heading_permalink' => [
                'insert' => HeadingPermalinkProcessor::INSERT_AFTER,
                'apply_id_to_heading' => true,
                'id_prefix' => '',
                'fragment_prefix' => '',
                'symbol' => '#',
            ],
            'table_of_contents' => [
                'position' => TableOfContentsBuilder::POSITION_PLACEHOLDER,
                'placeholder' => '[TOC]',
                'min_heading_level' => 2,
                'max_heading_level' => 4,
                'html_class' => 'studio-markdown-toc',
            ],
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function safeConfig(): array
    {
        return [
            'allow_unsafe_links' => false,
            'html_input' => 'escape',
        ];
    }

    private function embedVideoTitle(): string
    {
        return $this->translator?->trans(self::EMBED_VIDEO_TITLE_KEY) ?? self::EMBED_VIDEO_TITLE_KEY;
    }
}
