<?php

declare(strict_types=1);

namespace App\Security\Abuse;

final readonly class SuspiciousProbePathMatcher
{
    /**
     * @var list<string>
     */
    private const DEFAULT_PATTERNS = [
        '#/(?:\.env|\.git|\.svn|\.hg)(?:/|$)#i',
        '#/(?:wp-admin|wp-login\.php|xmlrpc\.php|phpmyadmin|pma|adminer\.php)(?:/|$)#i',
        '#/(?:backup|dump|database|db|site|www|wordpress)[^/]*\.(?:sql|sqlite|db|bak|old|zip|tar|gz|tgz|7z|rar)(?:$|[?\#])#i',
        '#/(?:shell|cmd|webshell|wso|c99|r57)\.php(?:$|[?\#])#i',
        '#/(?:vendor/phpunit|boaform|cgi-bin|HNAP1|actuator|server-status)(?:/|$)#i',
        '#/(?:\.DS_Store|composer\.(?:json|lock)|package-lock\.json)(?:$|[?\#])#i',
    ];

    /**
     * @param list<string> $patterns
     */
    public function __construct(private array $patterns = self::DEFAULT_PATTERNS)
    {
    }

    public function isProbe(string $path): bool
    {
        $path = '/'.ltrim(rawurldecode($path), '/');

        foreach ($this->patterns as $pattern) {
            if (1 === preg_match($pattern, $path)) {
                return true;
            }
        }

        return false;
    }
}
