<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Extension\ExtensionAssetUrlGenerator;
use App\Core\Extension\ExtensionRuntime;
use App\Core\Extension\ExtensionRuntimeServices;
use App\Tests\Support\FilesystemTestHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Asset\Package;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Asset\VersionStrategy\EmptyVersionStrategy;

final class ExtensionRuntimeAssetUrlTest extends TestCase
{
    use FilesystemTestHelper;

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = dirname(__DIR__, 3);
        $this->removeDirectory($this->projectDir.'/extensions/asset-url-facade');
        $this->removeDirectory($this->projectDir.'/assets/extensions/asset-url-facade');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir.'/extensions/asset-url-facade');
        $this->removeDirectory($this->projectDir.'/assets/extensions/asset-url-facade');
        ExtensionRuntime::reset();
    }

    public function testItReturnsUrlsForMirroredPublicAssets(): void
    {
        ExtensionRuntime::configure(new ExtensionRuntimeServices(
            $this->projectDir,
            assetUrls: new ExtensionAssetUrlGenerator($this->projectDir, new Packages(new Package(new EmptyVersionStrategy()))),
        ));
        $this->writeTestFile($this->projectDir, 'assets/extensions/asset-url-facade/images/icon.svg', '<svg></svg>');
        $this->writeExtensionFile(<<<'PHP'
            <?php

            return extension_asset_url('images/icon.svg');
            PHP);

        self::assertSame('extensions/asset-url-facade/images/icon.svg', require $this->projectDir.'/extensions/asset-url-facade/extension.php');
    }

    public function testItRejectsMissingInvalidPrivateAndNonExtensionUrls(): void
    {
        ExtensionRuntime::configure(new ExtensionRuntimeServices(
            $this->projectDir,
            assetUrls: new ExtensionAssetUrlGenerator($this->projectDir, new Packages(new Package(new EmptyVersionStrategy()))),
        ));
        self::assertNull(ExtensionRuntime::assetUrl('images/icon.svg'));

        $this->writeTestFile($this->projectDir, 'extensions/asset-url-facade/private-assets/secret.svg', '<svg></svg>');
        $this->writeExtensionFile(<<<'PHP'
            <?php

            return [
                extension_asset_url('../extension.php'),
                extension_asset_url('/etc/passwd'),
                extension_asset_url('secret.svg'),
            ];
            PHP);

        self::assertSame([null, null, null], require $this->projectDir.'/extensions/asset-url-facade/extension.php');
    }

    public function testItRejectsSymlinkedMirrorPaths(): void
    {
        ExtensionRuntime::configure(new ExtensionRuntimeServices(
            $this->projectDir,
            assetUrls: new ExtensionAssetUrlGenerator($this->projectDir, new Packages(new Package(new EmptyVersionStrategy()))),
        ));
        $this->writeTestFile($this->projectDir, 'extensions/asset-url-facade/private-assets/secret.svg', '<svg></svg>');
        mkdir($this->projectDir.'/assets/extensions/asset-url-facade', 0777, true);
        $this->createSymlinkOrSkip(
            $this->projectDir.'/extensions/asset-url-facade/private-assets',
            $this->projectDir.'/assets/extensions/asset-url-facade/linked',
        );
        $this->writeExtensionFile(<<<'PHP'
            <?php

            return extension_asset_url('linked/secret.svg');
            PHP);

        self::assertNull(require $this->projectDir.'/extensions/asset-url-facade/extension.php');
    }

    private function writeExtensionFile(string $contents): void
    {
        $this->writeTestFile($this->projectDir, 'extensions/asset-url-facade/extension.php', $contents);
    }
}
