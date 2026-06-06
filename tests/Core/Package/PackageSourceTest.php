<?php

declare(strict_types=1);

namespace App\Tests\Core\Package;

use App\Core\Package\PackageSource;
use App\Tests\Support\FilesystemTestHelper;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PackageSourceTest extends TestCase
{
    use FilesystemTestHelper;

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = $this->createTemporaryDirectory('system-package-source');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    public function testItAcceptsTheProjectRootAsSingleSource(): void
    {
        $source = PackageSource::single('app', '.');

        self::assertSame('.', $source->relativePath());
        self::assertSame([$this->projectDir], $source->candidateDirectories($this->projectDir));
    }

    public function testItRejectsPackageSourcePathsOutsideTheProjectRoot(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must stay inside its root');

        PackageSource::children('external', '../shared');
    }

    public function testItRejectsAbsolutePackageSourcePaths(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be relative');

        PackageSource::children('external', '/tmp/shared');
    }

    public function testItSkipsSymbolicPackageSourceChildren(): void
    {
        mkdir($this->projectDir.'/packages', 0775, true);
        mkdir($this->projectDir.'/packages/system', 0775, true);
        mkdir($this->projectDir.'/external', 0775, true);
        $this->createSymlinkOrSkip($this->projectDir.'/external', $this->projectDir.'/packages/external-link');

        $directories = PackageSource::children('package', 'packages')->candidateDirectories($this->projectDir);

        self::assertSame([str_replace('\\', '/', $this->projectDir.'/packages/system')], array_map(
            static fn (string $path): string => str_replace('\\', '/', $path),
            $directories,
        ));
    }
}
