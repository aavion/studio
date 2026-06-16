<?php

declare(strict_types=1);

namespace App\Security\Abuse;

use App\Core\Config\Config;

final readonly class SuspiciousProbePathMatcher
{
    public const PATTERNS_KEY = 'security.probe_path_patterns';

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

    /**
     * @param list<string>|null $patterns
     */
    public function __construct(
        private ?Config $config = null,
        private ?array $patterns = null,
    ) {
    }

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
        if (null !== $this->patterns) {
            return $this->normalizePatterns($this->patterns);
        }

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

            foreach (str_getcsv($line, ',', '"', '\\') as $value) {
                $value = trim((string) $value);

                if ('' !== $value) {
                    $patterns[] = $value;
                }
            }
        }

        return $patterns;
    }
}
