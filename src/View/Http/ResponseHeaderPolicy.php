<?php

declare(strict_types=1);

namespace App\View\Http;

final class ResponseHeaderPolicy
{
    /**
     * @var array<string, true>
     */
    private const BLOCKED_SET_HEADERS = [
        'authorization' => true,
        'connection' => true,
        'content-length' => true,
        'content-security-policy' => true,
        'content-security-policy-report-only' => true,
        'cookie' => true,
        'host' => true,
        'permissions-policy' => true,
        'proxy-authorization' => true,
        'referrer-policy' => true,
        'set-cookie' => true,
        'strict-transport-security' => true,
        'transfer-encoding' => true,
        'www-authenticate' => true,
        'x-content-type-options' => true,
        'x-frame-options' => true,
    ];

    /**
     * @var array<string, true>
     */
    private const BLOCKED_REMOVE_HEADERS = self::BLOCKED_SET_HEADERS + [
        'clear-site-data' => true,
    ];

    public function canSet(string $name, string|array $value): bool
    {
        return $this->isValidName($name)
            && !isset(self::BLOCKED_SET_HEADERS[$this->normalizeName($name)])
            && $this->isValidValue($value);
    }

    public function canRemove(string $name): bool
    {
        return $this->isValidName($name)
            && !isset(self::BLOCKED_REMOVE_HEADERS[$this->normalizeName($name)]);
    }

    private function isValidName(string $name): bool
    {
        return 1 === preg_match('/\A[a-zA-Z0-9!#$%&\'*+.^_`|~-]+\z/', $name);
    }

    private function normalizeName(string $name): string
    {
        return strtolower($name);
    }

    private function isValidValue(string|array $value): bool
    {
        foreach (is_array($value) ? $value : [$value] as $item) {
            if (!is_string($item) || str_contains($item, "\r") || str_contains($item, "\n") || str_contains($item, "\0")) {
                return false;
            }
        }

        return true;
    }
}
