<?php

declare(strict_types=1);

namespace App\Backend;

final readonly class ExtensionAdminLinkResolver
{
    public function sourceUrl(?string $source, ?string $channel): ?string
    {
        if (null === $source || '' === trim($source)) {
            return null;
        }

        $source = preg_replace('/\.git$/', '', trim($source)) ?? trim($source);
        $safeSource = $this->safeExternalUrl($source);

        if (null === $safeSource) {
            return null;
        }

        if (null === $channel || '' === trim($channel)) {
            return $safeSource;
        }

        if (1 === preg_match('#^https://github\.com/[^/\s]+/[^/\s]+$#', $safeSource)) {
            return rtrim($safeSource, '/').'/tree/'.rawurlencode(trim($channel));
        }

        return $safeSource;
    }

    public function safeExternalUrl(?string $url): ?string
    {
        if (null === $url || '' === trim($url)) {
            return null;
        }

        $url = trim($url);

        if (str_contains($url, '\\') || 1 === preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return null;
        }

        $parts = parse_url($url);

        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = $parts['host'] ?? null;

        return is_string($host) && '' !== trim($host) ? $url : null;
    }
}
