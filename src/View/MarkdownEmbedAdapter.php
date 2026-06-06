<?php

declare(strict_types=1);

namespace App\View;

use League\CommonMark\Extension\Embed\Embed;
use League\CommonMark\Extension\Embed\EmbedAdapterInterface;

final class MarkdownEmbedAdapter implements EmbedAdapterInterface
{
    public function __construct(private readonly string $videoTitle)
    {
    }

    /**
     * @param Embed[] $embeds
     */
    public function updateEmbeds(array $embeds): void
    {
        foreach ($embeds as $embed) {
            $embed->setEmbedCode($this->embedCode($embed->getUrl()));
        }
    }

    private function embedCode(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        $videoId = match (true) {
            in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true) => $this->youtubeWatchId($url),
            in_array($host, ['youtu.be', 'www.youtu.be'], true) => ltrim($path, '/'),
            default => null,
        };

        if (null === $videoId || ! preg_match('/^[A-Za-z0-9_-]{6,32}$/', $videoId)) {
            return null;
        }

        $src = 'https://www.youtube-nocookie.com/embed/'.$videoId;

        return sprintf(
            '<div class="studio-markdown-embed"><iframe src="%s" title="%s" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe></div>',
            htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($this->videoTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
    }

    private function youtubeWatchId(string $url): ?string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return isset($query['v']) && is_string($query['v']) ? $query['v'] : null;
    }
}
