<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Extension\ExtensionVendorFacade;
use App\Tests\Support\FilesystemTestHelper;
use PHPUnit\Framework\TestCase;

final class ExtensionVendorFacadeTest extends TestCase
{
    use FilesystemTestHelper;

    private string $projectDir;
    private string $extensionDir;

    protected function setUp(): void
    {
        $this->projectDir = dirname(__DIR__, 3);
        $this->extensionDir = $this->projectDir.'/extensions/vendor-facade-test';
        $this->removeDirectory($this->extensionDir);
        mkdir($this->extensionDir, 0777, true);
        ExtensionVendorFacade::reset();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->extensionDir);
        ExtensionVendorFacade::reset();
    }

    public function testRequireVendorRegistersExtensionOwnedPsr4Package(): void
    {
        $this->writeExtensionPackage('acme/tool', 'Acme\\Tool\\', 'src');
        $this->writeProjectFile('extensions/vendor-facade-test/vendor/acme/tool/src/Widget.php', <<<'PHP'
            <?php

            namespace Acme\Tool;

            final class Widget
            {
                public static function label(): string
                {
                    return 'loaded';
                }
            }
            PHP);
        $this->writeProjectFile('extensions/vendor-facade-test/extension.php', <<<'PHP'
            <?php

            return require_vendor('acme/tool') && class_exists(\Acme\Tool\Widget::class);
            PHP);

        self::assertTrue(require $this->extensionDir.'/extension.php');
        self::assertSame('loaded', \Acme\Tool\Widget::label());
    }

    public function testRequireVendorDoesNotExecuteComposerAutoloadFiles(): void
    {
        $this->writeExtensionPackage('acme/no-files', 'Acme\\NoFiles\\', 'src', [
            'files' => ['bootstrap.php'],
        ]);
        $this->writeProjectFile('extensions/vendor-facade-test/vendor/acme/no-files/bootstrap.php', <<<'PHP'
            <?php

            file_put_contents(__DIR__.'/autoload-file-executed.txt', 'yes');
            PHP);
        $this->writeProjectFile('extensions/vendor-facade-test/vendor/autoload.php', <<<'PHP'
            <?php

            file_put_contents(__DIR__.'/autoload-executed.txt', 'yes');
            PHP);
        $this->writeProjectFile('extensions/vendor-facade-test/vendor/acme/no-files/src/Marker.php', <<<'PHP'
            <?php

            namespace Acme\NoFiles;

            final class Marker
            {
            }
            PHP);
        $this->writeProjectFile('extensions/vendor-facade-test/extension.php', <<<'PHP'
            <?php

            return require_vendor('acme/no-files') && class_exists(\Acme\NoFiles\Marker::class);
            PHP);

        self::assertTrue(require $this->extensionDir.'/extension.php');
        self::assertFileDoesNotExist($this->extensionDir.'/vendor/autoload-executed.txt');
        self::assertFileDoesNotExist($this->extensionDir.'/vendor/acme/no-files/autoload-file-executed.txt');
    }

    public function testRequireVendorReturnsFalseForInvalidOrMissingPackages(): void
    {
        $this->writeProjectFile('extensions/vendor-facade-test/extension.php', <<<'PHP'
            <?php

            return [
                require_vendor('../invalid'),
                require_vendor('missing/package'),
            ];
            PHP);

        self::assertSame([false, false], require $this->extensionDir.'/extension.php');
    }

    public function testRequireVendorDoesNotExposeCorePackageLookupOutsideExtensionCallers(): void
    {
        self::assertFalse(ExtensionVendorFacade::requireVendor('symfony/http-foundation'));

        $this->writeProjectFile('extensions/vendor-facade-test/extension.php', <<<'PHP'
            <?php

            return require_vendor('symfony/http-foundation');
            PHP);

        self::assertTrue(require $this->extensionDir.'/extension.php');
    }

    public function testRequireVendorRejectsPsr4PrefixesThatOverlapExistingComposerPrefixes(): void
    {
        $this->writeExtensionPackage('acme/app-spoof', 'App\\', 'src');
        $this->writeProjectFile('extensions/vendor-facade-test/vendor/acme/app-spoof/src/Spoof.php', <<<'PHP'
            <?php

            namespace App;

            final class Spoof
            {
            }
            PHP);
        $this->writeProjectFile('extensions/vendor-facade-test/extension.php', <<<'PHP'
            <?php

            return require_vendor('acme/app-spoof');
            PHP);

        self::assertFalse(require $this->extensionDir.'/extension.php');
        self::assertFalse(class_exists('App\\Spoof'));
    }

    /**
     * @param array<string, mixed> $extraAutoload
     */
    private function writeExtensionPackage(string $package, string $prefix, string $sourcePath, array $extraAutoload = []): void
    {
        $autoload = ['psr-4' => [$prefix => $sourcePath], ...$extraAutoload];
        $this->writeProjectFile('extensions/vendor-facade-test/vendor/composer/installed.json', json_encode([
            'packages' => [[
                'name' => $package,
                'version' => '1.0.0',
                'autoload' => $autoload,
                'install-path' => '../'.$package,
            ]],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        mkdir($this->extensionDir.'/vendor/'.$package.'/'.$sourcePath, 0777, true);
    }

    private function writeProjectFile(string $relativePath, string $contents): void
    {
        $path = $this->projectDir.'/'.$relativePath;
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, $contents);
    }
}
