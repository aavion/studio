<?php

declare(strict_types=1);

namespace App\Tests\Core\Statistics;

use App\Core\Statistics\UserAgentClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserAgentClassifierTest extends TestCase
{
    #[DataProvider('userAgentProvider')]
    public function testItClassifiesCommonUserAgents(string $userAgent, string $browserFamily, string $deviceType, bool $isBot): void
    {
        self::assertSame([
            'browser_family' => $browserFamily,
            'device_type' => $deviceType,
            'is_bot' => $isBot,
        ], (new UserAgentClassifier())->classify($userAgent));
    }

    /**
     * @return iterable<string, array{string, string, string, bool}>
     */
    public static function userAgentProvider(): iterable
    {
        yield 'desktop chrome' => [
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
            'chromium',
            'desktop',
            false,
        ];
        yield 'mobile safari' => [
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
            'safari',
            'mobile',
            false,
        ];
        yield 'firefox desktop' => [
            'Mozilla/5.0 (X11; Linux x86_64; rv:125.0) Gecko/20100101 Firefox/125.0',
            'firefox',
            'desktop',
            false,
        ];
        yield 'edge before chromium fallback' => [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36 Edg/125.0.0.0',
            'edge',
            'desktop',
            false,
        ];
        yield 'known bot' => [
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            'bot',
            'bot',
            true,
        ];
        yield 'empty' => [
            '',
            'other',
            'other',
            false,
        ];
    }
}
