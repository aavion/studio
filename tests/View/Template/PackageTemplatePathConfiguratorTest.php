<?php

declare(strict_types=1);

namespace App\Tests\View\Template;

use App\Core\Package\ActivePackageAssetProviderInterface;
use App\Core\Package\PackageAssetSyncPackage;
use App\Core\Package\PackageScope;
use App\Tests\Support\FilesystemTestHelper;
use App\View\Template\PackageTemplatePathConfigurator;
use App\View\Template\PackageTemplatePathResolver;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class PackageTemplatePathConfiguratorTest extends TestCase
{
    use FilesystemTestHelper;

    private string $root;

    protected function setUp(): void
    {
        $this->root = $this->createTemporaryDirectory('studio-template-paths');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testItRegistersScopedPackagePathsOnTwigFilesystemLoader(): void
    {
        $this->writeTestFile($this->root, 'templates/frontend/.keep', '');
        $this->writeTestFile($this->root, 'templates/backend/.keep', '');
        $this->writeTestFile($this->root, 'templates/.keep', '');
        $this->writeTestFile($this->root, 'packages/theme/templates/frontend/.keep', '');
        $this->writeTestFile($this->root, 'packages/backend/templates/backend/.keep', '');
        $this->writeTestFile($this->root, 'packages/system/templates/.keep', '');
        $this->writeTestFile($this->root, 'packages/module/templates/.keep', '');

        $loader = new FilesystemLoader();
        $twig = new Environment($loader);
        $configurator = new PackageTemplatePathConfigurator(
            $twig,
            new StaticPackageProvider([
                new PackageAssetSyncPackage('theme', 'packages/theme', [PackageScope::FrontendTheme]),
                new PackageAssetSyncPackage('backend', 'packages/backend', [PackageScope::BackendTheme]),
                new PackageAssetSyncPackage('system', 'packages/system', [PackageScope::SystemTemplate]),
                new PackageAssetSyncPackage('module', 'packages/module', [PackageScope::Module]),
            ]),
            new PackageTemplatePathResolver($this->root),
        );

        $configurator->configure();

        self::assertSame([
            $this->root.'/packages/theme/templates/frontend',
            $this->root.'/templates/frontend',
        ], $loader->getPaths('frontend'));
        self::assertSame([
            $this->root.'/packages/backend/templates/backend',
            $this->root.'/templates/backend',
        ], $loader->getPaths('backend'));
        self::assertSame([
            $this->root.'/packages/system/templates',
            $this->root.'/templates',
            $this->root.'/packages/theme/templates',
            $this->root.'/packages/backend/templates',
            $this->root.'/packages/module/templates',
        ], $loader->getPaths('root'));
    }
}

final readonly class StaticPackageProvider implements ActivePackageAssetProviderInterface
{
    /**
     * @param list<PackageAssetSyncPackage> $packages
     */
    public function __construct(private array $packages)
    {
    }

    public function packages(): array
    {
        return $this->packages;
    }
}
