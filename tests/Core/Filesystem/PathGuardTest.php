<?php

declare(strict_types=1);

namespace App\Tests\Core\Filesystem;

use App\Core\Filesystem\PathGuard;
use App\Tests\Support\FilesystemTestHelper;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PathGuardTest extends TestCase
{
    use FilesystemTestHelper;

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function validPathProvider(): iterable
    {
        yield 'plain file' => ['manifest.json', 'manifest.json'];
        yield 'nested path' => ['templates/base.html.twig', 'templates/base.html.twig'];
        yield 'leading current directory' => ['./assets/app.css', 'assets/app.css'];
        yield 'duplicate separators' => ['assets//styles/app.css', 'assets/styles/app.css'];
        yield 'windows separators' => ['assets\\styles\\app.css', 'assets/styles/app.css'];
    }

    #[DataProvider('validPathProvider')]
    public function testItNormalizesRelativePaths(string $path, string $expected): void
    {
        self::assertSame($expected, (new PathGuard())->relativePath($path));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function unsafePathProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'current directory only' => ['./'];
        yield 'absolute unix path' => ['/etc/passwd'];
        yield 'absolute windows path' => ['C:\\Windows\\system.ini'];
        yield 'parent traversal' => ['../outside.txt'];
        yield 'nested parent traversal' => ['assets/../outside.txt'];
        yield 'null byte' => ["assets/app.css\0.php"];
    }

    #[DataProvider('unsafePathProvider')]
    public function testItRejectsUnsafePaths(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PathGuard())->relativePath($path);
    }

    public function testItJoinsRelativePathInsideRoot(): void
    {
        self::assertSame(
            '/project/public/assets/app.css',
            (new PathGuard())->join('/project/public', './assets/app.css'),
        );
    }

    public function testItDetectsSymlinkAncestors(): void
    {
        $root = $this->createTemporaryDirectory('studio-path-guard');

        try {
            mkdir($root.'/external', 0775, true);
            mkdir($root.'/target', 0775, true);

            if (!@symlink($root.'/external', $root.'/target/linked')) {
                self::markTestSkipped('Symbolic links are not available in this environment.');
            }

            self::assertSame('target/linked', (new PathGuard())->symlinkAncestor($root, 'target/linked/file.txt'));
            self::assertNull((new PathGuard())->symlinkAncestor($root, 'target/file.txt'));
        } finally {
            $this->removeDirectory($root);
        }
    }
}
