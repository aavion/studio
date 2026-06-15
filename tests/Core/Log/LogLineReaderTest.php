<?php

declare(strict_types=1);

namespace App\Tests\Core\Log;

use App\Core\Log\LogLineReader;
use App\Tests\Support\FilesystemTestHelper;
use PHPUnit\Framework\TestCase;

final class LogLineReaderTest extends TestCase
{
    use FilesystemTestHelper;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = $this->createTemporaryDirectory('system-log-line-reader');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function testItReadsNewestNonEmptyLinesFirst(): void
    {
        $file = $this->directory.'/dev.log';
        file_put_contents($file, implode(PHP_EOL, [
            'oldest',
            '',
            'middle',
            'newest',
            '',
        ]));

        self::assertSame(['newest', 'middle', 'oldest'], (new LogLineReader())->readLines($file));
    }

    public function testItCapsReturnedLines(): void
    {
        $file = $this->directory.'/dev.log';
        $lines = [];

        for ($index = 1; $index <= 5100; ++$index) {
            $lines[] = sprintf('line-%04d %s', $index, str_repeat('x', 80));
        }

        file_put_contents($file, implode(PHP_EOL, $lines));

        $read = (new LogLineReader())->readLines($file);

        self::assertCount(5000, $read);
        self::assertStringStartsWith('line-5100 ', $read[0]);
        self::assertStringStartsWith('line-0101 ', $read[4999]);
    }
}
