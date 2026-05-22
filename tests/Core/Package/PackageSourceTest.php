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
        $this->projectDir = $this->createTemporaryDirectory('studio-package-source');
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
        mkdir($this->projectDir.'/themes', 0775, true);
        mkdir($this->projectDir.'/themes/system', 0775, true);
        mkdir($this->projectDir.'/external', 0775, true);
        symlink($this->projectDir.'/external', $this->projectDir.'/themes/external-link');

        $directories = PackageSource::children('theme', 'themes')->candidateDirectories($this->projectDir);

        self::assertSame([$this->projectDir.'/themes/system'], $directories);
    }
}
