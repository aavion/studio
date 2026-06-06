<?php

declare(strict_types=1);

namespace App\Tests\Core\Integrity;

use App\Core\Integrity\Checksum;
use App\Core\Integrity\ChecksumCalculator;
use App\Tests\Support\FilesystemTestHelper;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ChecksumCalculatorTest extends TestCase
{
    use FilesystemTestHelper;

    private string $root;

    protected function setUp(): void
    {
        $this->root = $this->createTemporaryDirectory('system-checksum');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testItCalculatesStringChecksums(): void
    {
        $checksum = (new ChecksumCalculator())->forString('studio');

        self::assertSame('sha256', $checksum->algorithm());
        self::assertSame(hash('sha256', 'studio'), $checksum->value());
        self::assertSame('sha256:'.$checksum->value(), $checksum->toIntegrityString());
    }

    public function testItCalculatesFileChecksums(): void
    {
        $path = $this->root.'/file.txt';
        file_put_contents($path, 'contents');

        $checksum = (new ChecksumCalculator())->forFile($path);

        self::assertSame(hash('sha256', 'contents'), $checksum->value());
    }

    public function testItCalculatesDeterministicFileSetChecksums(): void
    {
        $this->writeTestFile($this->root, 'b.txt', 'second');
        $this->writeTestFile($this->root, 'a.txt', 'first');

        $calculator = new ChecksumCalculator();

        self::assertTrue($calculator->forFileSet($this->root, ['a.txt', 'b.txt'])->equals(
            $calculator->forFileSet($this->root, ['a.txt', 'b.txt']),
        ));
        self::assertFalse($calculator->forFileSet($this->root, ['a.txt', 'b.txt'])->equals(
            $calculator->forFileSet($this->root, ['b.txt', 'a.txt']),
        ));
    }

    public function testItRejectsUnsupportedAlgorithms(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ChecksumCalculator('unsupported');
    }

    public function testItRejectsUnreadableFiles(): void
    {
        $this->expectException(RuntimeException::class);

        (new ChecksumCalculator())->forFile($this->root.'/missing.txt');
    }

    public function testItComparesChecksumsSafely(): void
    {
        self::assertTrue(Checksum::sha256('abc')->equals(Checksum::sha256('abc')));
        self::assertFalse(Checksum::sha256('abc')->equals(Checksum::sha256('def')));
    }

}
