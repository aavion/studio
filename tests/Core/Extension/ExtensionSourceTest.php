<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Extension\ExtensionSource;
use App\Tests\Support\FilesystemTestHelper;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ExtensionSourceTest extends TestCase
{
    use FilesystemTestHelper;

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = $this->createTemporaryDirectory('system-extension-source');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    public function testItAcceptsTheProjectRootAsSingleSource(): void
    {
        $source = ExtensionSource::single('app', '.');

        self::assertSame('.', $source->relativePath());
        self::assertSame([$this->projectDir], $source->candidateDirectories($this->projectDir));
    }

    public function testItRejectsExtensionSourcePathsOutsideTheProjectRoot(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must stay inside its root');

        ExtensionSource::children('external', '../shared');
    }

    public function testItRejectsAbsoluteExtensionSourcePaths(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be relative');

        ExtensionSource::children('external', '/tmp/shared');
    }

    public function testItSkipsSymbolicExtensionSourceChildren(): void
    {
        mkdir($this->projectDir.'/extensions', 0775, true);
        mkdir($this->projectDir.'/extensions/system', 0775, true);
        mkdir($this->projectDir.'/external', 0775, true);
        $this->createSymlinkOrSkip($this->projectDir.'/external', $this->projectDir.'/extensions/external-link');

        $directories = ExtensionSource::children('extension', 'extensions')->candidateDirectories($this->projectDir);

        self::assertSame([str_replace('\\', '/', $this->projectDir.'/extensions/system')], array_map(
            static fn (string $path): string => str_replace('\\', '/', $path),
            $directories,
        ));
    }
}
