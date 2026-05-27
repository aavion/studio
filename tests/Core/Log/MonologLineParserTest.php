<?php

declare(strict_types=1);

namespace App\Tests\Core\Log;

use App\Core\Log\MonologLineParser;
use PHPUnit\Framework\TestCase;

final class MonologLineParserTest extends TestCase
{
    public function testItParsesMonologLinesWithContext(): void
    {
        $entry = (new MonologLineParser())->parse(
            '[2026-05-27T10:00:00.000000+00:00] studio_access.INFO: access.request {"method":"GET","path":"/","http_status":200} []',
            '/tmp/test.studio-access.log',
        );

        self::assertSame('2026-05-27T10:00:00.000000+00:00', $entry['timestamp']);
        self::assertSame('studio_access', $entry['channel']);
        self::assertSame('INFO', $entry['level']);
        self::assertSame('access.request', $entry['message']);
        self::assertSame('GET', $entry['context']['method']);
        self::assertSame('test.studio-access.log', $entry['file']);
    }

    public function testItKeepsUnmatchedLinesAsRawMessages(): void
    {
        $entry = (new MonologLineParser())->parse('plain log line');

        self::assertSame('plain log line', $entry['message']);
        self::assertSame('plain log line', $entry['raw']);
        self::assertSame([], $entry['context']);
    }
}
