<?php

declare(strict_types=1);

namespace App\Theme;

final class MarkdownRenderer
{
    public function render(string $markdown): string
    {
        $markdown = trim(str_replace(["\r\n", "\r"], "\n", $markdown));

        if ('' === $markdown) {
            return '';
        }

        $blocks = preg_split('/\n{2,}/', $markdown) ?: [];
        $html = [];

        foreach ($blocks as $block) {
            $block = trim($block);

            if ('' === $block) {
                continue;
            }

            if (str_starts_with($block, '```') && str_ends_with($block, '```')) {
                $code = trim(substr($block, 3, -3));
                $html[] = '<pre><code>'.$this->escape($code).'</code></pre>';

                continue;
            }

            if (1 === preg_match('/^(#{1,3})\s+(.+)$/', $block, $matches)) {
                $level = strlen($matches[1]);
                $html[] = sprintf('<h%d>%s</h%d>', $level, $this->renderInline($matches[2]), $level);

                continue;
            }

            if (1 === preg_match('/^-\s+/m', $block)) {
                $items = array_filter(array_map('trim', explode("\n", $block)));
                $listItems = [];

                foreach ($items as $item) {
                    if (!str_starts_with($item, '- ')) {
                        continue 2;
                    }

                    $listItems[] = '<li>'.$this->renderInline(substr($item, 2)).'</li>';
                }

                $html[] = '<ul>'.implode('', $listItems).'</ul>';

                continue;
            }

            $html[] = '<p>'.$this->renderInline(str_replace("\n", ' ', $block)).'</p>';
        }

        return implode("\n", $html);
    }

    private function renderInline(string $text): string
    {
        $escaped = $this->escape($text);
        $escaped = preg_replace_callback('/`([^`]+)`/', static function (array $matches): string {
            return '<code>'.$matches[1].'</code>';
        }, $escaped) ?? $escaped;
        $escaped = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $escaped) ?? $escaped;
        $escaped = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $escaped) ?? $escaped;

        return preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/', function (array $matches): string {
            $label = $matches[1];
            $url = html_entity_decode($matches[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            if (false === filter_var($url, FILTER_VALIDATE_URL)) {
                return $label;
            }

            return sprintf(
                '<a href="%s" rel="noopener noreferrer">%s</a>',
                $this->escape($url),
                $label,
            );
        }, $escaped) ?? $escaped;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
