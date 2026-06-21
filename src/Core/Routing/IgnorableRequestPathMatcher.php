<?php

declare(strict_types=1);

namespace App\Core\Routing;

final readonly class IgnorableRequestPathMatcher
{
    private const PREFIXES = ['/assets', '/build', '/_profiler', '/_wdt'];

    private const EXACT_PATHS = [
        '/ads.txt',
        '/app-ads.txt',
        '/apple-touch-icon.png',
        '/apple-touch-icon-precomposed.png',
        '/browserconfig.xml',
        '/favicon-16x16.png',
        '/favicon-32x32.png',
        '/favicon.ico',
        '/humans.txt',
        '/manifest.json',
        '/mstile-150x150.png',
        '/robots.txt',
        '/safari-pinned-tab.svg',
        '/site.webmanifest',
        '/sitemap.xml',
        '/.well-known/apple-app-site-association',
        '/.well-known/assetlinks.json',
        '/.well-known/change-password',
        '/.well-known/host-meta',
        '/.well-known/host-meta.json',
        '/.well-known/mercure',
        '/.well-known/nodeinfo',
        '/.well-known/security.txt',
        '/.well-known/webfinger',
    ];

    private PathScopeMatcher $paths;

    public function __construct(?PathScopeMatcher $paths = null)
    {
        $this->paths = $paths ?? new PathScopeMatcher();
    }

    public function matches(string $path): bool
    {
        return $this->paths->matchesAnyPrefix($path, ...self::PREFIXES)
            || in_array($path, self::EXACT_PATHS, true);
    }
}
