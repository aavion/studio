<?php

declare(strict_types=1);

namespace App\Security\Abuse;

use App\Core\Config\Config;
use Psr\Cache\CacheItemInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Throwable;

final class SuspiciousProbePathMatcher
{
    public const PATTERNS_KEY = 'security.probe_path_patterns';
    public const CACHE_KEY = 'security.suspicious_probe_path_patterns.v1';

    /**
     * @var list<string>
     */
    public const DEFAULT_PATTERNS = [
        '#/(?:\.env|\.git|\.svn|\.hg)(?:/|$)#i',
        '#/(?:wp-admin|wp-login\.php|xmlrpc\.php|phpmyadmin|pma|adminer\.php)(?:/|$)#i',
        '#/(?:backup|dump|database|db|site|www|wordpress)[^/]*\.(?:sql|sqlite|db|bak|old|zip|tar|gz|tgz|7z|rar)(?:$|[?\#])#i',
        '#/(?:shell|cmd|webshell|wso|c99|r57)\.php(?:$|[?\#])#i',
        '#/(?:vendor/phpunit|boaform|cgi-bin|HNAP1|actuator|server-status)(?:/|$)#i',
        '#/(?:\.DS_Store|composer\.(?:json|lock)|package-lock\.json)(?:$|[?\#])#i',
    ];

    private const MAX_PATTERN_COUNT = 100;
    private const MAX_PATTERN_LENGTH = 500;
    private const CACHE_TTL_SECONDS = 300;

    /**
     * @param list<string>|null $patterns
     */
    public function __construct(
        private readonly ?Config $config = null,
        private readonly ?array $patterns = null,
        private readonly ?CacheInterface $cache = null,
    ) {
    }

    /**
     * @var list<string>|null
     */
    private ?array $activePatterns = null;

    public static function defaultPatternText(): string
    {
        return implode("\n", self::DEFAULT_PATTERNS);
    }

    public function isProbe(string $path): bool
    {
        $path = '/'.ltrim(rawurldecode($path), '/');

        foreach ($this->activePatterns() as $pattern) {
            if (1 === preg_match($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function activePatterns(): array
    {
        if (null !== $this->activePatterns) {
            return $this->activePatterns;
        }

        if (null !== $this->patterns) {
            return $this->activePatterns = $this->normalizePatterns($this->patterns);
        }

        if (null !== $this->cache) {
            try {
                return $this->activePatterns = $this->cache->get(
                    self::CACHE_KEY,
                    function (CacheItemInterface $item): array {
                        $item->expiresAfter(self::CACHE_TTL_SECONDS);

                        return $this->loadConfiguredPatterns();
                    },
                );
            } catch (Throwable) {
                return $this->activePatterns = $this->loadConfiguredPatterns();
            }
        }

        return $this->activePatterns = $this->loadConfiguredPatterns();
    }

    public function resetCache(): void
    {
        $this->activePatterns = null;

        try {
            $this->cache?->delete(self::CACHE_KEY);
        } catch (Throwable) {
        }
    }

    /**
     * @return list<string>
     */
    private function loadConfiguredPatterns(): array
    {
        $configured = $this->config?->get(self::PATTERNS_KEY, self::defaultPatternText()) ?? self::defaultPatternText();

        return $this->normalizePatterns($configured);
    }

    /**
     * @param mixed $patterns
     *
     * @return list<string>
     */
    private function normalizePatterns(mixed $patterns): array
    {
        $candidates = is_array($patterns)
            ? $patterns
            : $this->parsePatternText(is_string($patterns) ? $patterns : '');
        $valid = [];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }

            $pattern = trim($candidate);

            if ('' === $pattern || self::MAX_PATTERN_LENGTH < strlen($pattern)) {
                continue;
            }

            if (false === @preg_match($pattern, '/probe-test')) {
                continue;
            }

            $valid[] = $pattern;

            if (self::MAX_PATTERN_COUNT <= count($valid)) {
                break;
            }
        }

        return [] === $valid ? self::DEFAULT_PATTERNS : array_values(array_unique($valid));
    }

    /**
     * @return list<string>
     */
    private function parsePatternText(string $text): array
    {
        $patterns = [];
        $lines = preg_split('/\R+/', $text);

        foreach (false === $lines ? [$text] : $lines as $line) {
            $line = trim($line);

            if ('' === $line) {
                continue;
            }

            if (!$this->looksLikeQuotedCsv($line)) {
                $patterns[] = $line;

                continue;
            }

            foreach (str_getcsv($line, ',', '"', '\\') as $value) {
                $value = trim((string) $value);

                if ('' !== $value) {
                    $patterns[] = $value;
                }
            }
        }

        return $patterns;
    }

    private function looksLikeQuotedCsv(string $line): bool
    {
        return str_contains($line, ',') && str_starts_with(ltrim($line), '"');
    }
}
