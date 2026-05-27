<?php

declare(strict_types=1);

namespace App\Core\Statistics;

final readonly class UserAgentClassifier
{
    /**
     * @return array{browser_family: string, device_type: string, is_bot: bool}
     */
    public function classify(?string $userAgent): array
    {
        $normalized = strtolower(trim((string) $userAgent));

        if ('' === $normalized || 'n/a' === $normalized) {
            return $this->result('other', 'other', false);
        }

        if ($this->isBot($normalized)) {
            return $this->result('bot', 'bot', true);
        }

        return $this->result($this->browserFamily($normalized), $this->deviceType($normalized), false);
    }

    /**
     * @return array{browser_family: string, device_type: string, is_bot: bool}
     */
    private function result(string $browserFamily, string $deviceType, bool $isBot): array
    {
        return [
            'browser_family' => $browserFamily,
            'device_type' => $deviceType,
            'is_bot' => $isBot,
        ];
    }

    private function isBot(string $userAgent): bool
    {
        return 1 === preg_match('/bot|crawler|spider|slurp|bingpreview|facebookexternalhit|linkedinbot|whatsapp|telegrambot|discordbot|googlebot|bingbot|duckduckbot|baiduspider|yandexbot/i', $userAgent);
    }

    private function browserFamily(string $userAgent): string
    {
        return match (true) {
            str_contains($userAgent, 'edg/')
                || str_contains($userAgent, 'edge/')
                || str_contains($userAgent, 'edgios')
                || str_contains($userAgent, 'edga') => 'edge',
            str_contains($userAgent, 'opr/') || str_contains($userAgent, 'opera') => 'opera',
            str_contains($userAgent, 'samsungbrowser') => 'samsung',
            str_contains($userAgent, 'firefox') || str_contains($userAgent, 'fxios') => 'firefox',
            str_contains($userAgent, 'chromium')
                || str_contains($userAgent, 'chrome/')
                || str_contains($userAgent, 'crios') => 'chromium',
            str_contains($userAgent, 'safari') || str_contains($userAgent, 'applewebkit') => 'safari',
            default => 'other',
        };
    }

    private function deviceType(string $userAgent): string
    {
        return match (true) {
            str_contains($userAgent, 'ipad')
                || str_contains($userAgent, 'tablet')
                || str_contains($userAgent, 'kindle')
                || str_contains($userAgent, 'silk/')
                || str_contains($userAgent, 'playbook')
                || str_contains($userAgent, 'nexus 7')
                || str_contains($userAgent, 'nexus 10') => 'tablet',
            str_contains($userAgent, 'mobile')
                || str_contains($userAgent, 'iphone')
                || str_contains($userAgent, 'ipod')
                || str_contains($userAgent, 'windows phone')
                || (str_contains($userAgent, 'android') && !str_contains($userAgent, 'tablet')) => 'mobile',
            default => 'desktop',
        };
    }
}
