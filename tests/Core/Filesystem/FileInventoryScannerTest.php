<?php

declare(strict_types=1);

namespace App\Tests\Core\Filesystem;

use App\Core\Filesystem\FileInventoryScanner;
use App\Tests\Support\FilesystemTestHelper;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FileInventoryScannerTest extends TestCase
{
    use FilesystemTestHelper;

    private string $root;

    protected function setUp(): void
    {
        $this->root = $this->createTemporaryDirectory('system-file-inventory');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testItScansSortedFilesAndDirectories(): void
    {
        $this->writeTestFile($this->root, 'templates/base.html.twig', '<main></main>');
        $this->writeTestFile($this->root, 'assets/app.css', 'body {}');
        $this->writeTestFile($this->root, 'src/Example.php', '<?php class Example {}');

        $inventory = (new FileInventoryScanner())->scan($this->root, 2);

        self::assertSame([
            'assets/',
            'assets/app.css',
            'src/',
            'src/Example.php',
            'templates/',
            'templates/base.html.twig',
        ], $inventory->entries());
        self::assertSame(['assets/', 'src/', 'templates/'], $inventory->directories());
        self::assertSame(['assets/app.css', 'src/Example.php', 'templates/base.html.twig'], $inventory->files());
        self::assertSame(['templates/base.html.twig'], $inventory->filesWhere(static fn (string $path): bool => str_ends_with($path, '.twig')));
    }

    public function testItLimitsDepth(): void
    {
        $this->writeTestFile($this->root, 'one/two/three/file.txt', 'nested');

        $inventory = (new FileInventoryScanner())->scan($this->root, 1);

        self::assertContains('one/', $inventory->entries());
        self::assertContains('one/two/', $inventory->entries());
        self::assertNotContains('one/two/three/', $inventory->entries());
        self::assertNotContains('one/two/three/file.txt', $inventory->entries());
    }

    public function testItSkipsSymbolicLinks(): void
    {
        $this->writeTestFile($this->root, 'outside.txt', 'outside');
        $this->createSymlinkOrSkip($this->root.'/outside.txt', $this->root.'/linked.txt');

        $inventory = (new FileInventoryScanner())->scan($this->root, 2);

        self::assertNotContains('linked.txt', $inventory->entries());
    }

    public function testItReturnsEmptyInventoryForMissingRoot(): void
    {
        $inventory = (new FileInventoryScanner())->scan($this->root.'/missing');

        self::assertSame([], $inventory->entries());
    }

    public function testItNormalizesTrailingDirectorySeparators(): void
    {
        $this->writeTestFile($this->root, 'src/Example.php', '<?php class Example {}');

        $inventory = (new FileInventoryScanner())->scan($this->root.'\\', 1);

        self::assertSame(['src/', 'src/Example.php'], $inventory->entries());
    }

    public function testItRejectsNegativeDepth(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('File inventory depth must not be negative.');

        (new FileInventoryScanner())->scan($this->root, -1);
    }
}
