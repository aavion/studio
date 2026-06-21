<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Extension\ExtensionAssetReader;
use App\Core\Extension\ExtensionRuntime;
use App\Core\Extension\ExtensionRuntimeServices;
use App\Tests\Support\FilesystemTestHelper;
use PHPUnit\Framework\TestCase;

final class ExtensionRuntimeAssetTest extends TestCase
{
    use FilesystemTestHelper;

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = dirname(__DIR__, 3);
        $this->removeDirectory($this->projectDir.'/extensions/asset-facade');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir.'/extensions/asset-facade');
        ExtensionRuntime::reset();
    }

    public function testItReadsPublicAndPrivateExtensionAssets(): void
    {
        ExtensionRuntime::configure(new ExtensionRuntimeServices($this->projectDir, assets: new ExtensionAssetReader($this->projectDir)));
        $this->writeTestFile($this->projectDir, 'extensions/asset-facade/assets/images/icon.svg', '<svg></svg>');
        $this->writeTestFile($this->projectDir, 'extensions/asset-facade/private-assets/challenges/index.json', '{"ok":true}');
        $this->writeExtensionFile(<<<'PHP'
            <?php

            return [
                extension_asset('images/icon.svg'),
                extension_asset('challenges/index.json', true),
            ];
            PHP);

        self::assertSame(['<svg></svg>', '{"ok":true}'], require $this->projectDir.'/extensions/asset-facade/extension.php');
    }

    public function testItRejectsInvalidMissingOversizedAndNonExtensionReads(): void
    {
        ExtensionRuntime::configure(new ExtensionRuntimeServices($this->projectDir, assets: new ExtensionAssetReader($this->projectDir)));
        self::assertNull(ExtensionRuntime::asset('images/icon.svg'));

        $this->writeTestFile($this->projectDir, 'extensions/asset-facade/assets/images/icon.svg', '<svg></svg>');
        $this->writeTestFile($this->projectDir, 'extensions/asset-facade/assets/large.bin', str_repeat('x', ExtensionAssetReader::MAX_BYTES + 1));
        $this->writeExtensionFile(<<<'PHP'
            <?php

            return [
                extension_asset('../extension.php'),
                extension_asset('/etc/passwd'),
                extension_asset('missing.txt'),
                extension_asset('images'),
                extension_asset('large.bin'),
            ];
            PHP);

        self::assertSame([null, null, null, null, null], require $this->projectDir.'/extensions/asset-facade/extension.php');
    }

    public function testItRejectsSymlinkedAssetPaths(): void
    {
        ExtensionRuntime::configure(new ExtensionRuntimeServices($this->projectDir, assets: new ExtensionAssetReader($this->projectDir)));
        $this->writeTestFile($this->projectDir, 'extensions/asset-facade/private-assets/secret.txt', 'secret');
        mkdir($this->projectDir.'/extensions/asset-facade/assets', 0777, true);
        $this->createSymlinkOrSkip(
            $this->projectDir.'/extensions/asset-facade/private-assets',
            $this->projectDir.'/extensions/asset-facade/assets/linked',
        );
        $this->writeExtensionFile(<<<'PHP'
            <?php

            return extension_asset('linked/secret.txt');
            PHP);

        self::assertNull(require $this->projectDir.'/extensions/asset-facade/extension.php');
    }

    private function writeExtensionFile(string $contents): void
    {
        $this->writeTestFile($this->projectDir, 'extensions/asset-facade/extension.php', $contents);
    }
}
