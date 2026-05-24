<?php

declare(strict_types=1);

namespace App\Tests\Core\Package;

use App\Core\Package\PackageAssetContribution;
use App\Core\Package\PackageAssetRegistryBuilder;
use App\Core\Package\PackageScope;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PackageAssetRegistryBuilderTest extends TestCase
{
    public function testItBuildsCssRegistryForExtensionPackages(): void
    {
        $builder = new PackageAssetRegistryBuilder();

        $registry = $builder->buildCssRegistry([
            PackageAssetContribution::css('frontend-theme', PackageScope::FrontendTheme, 'assets/packages/frontend-theme/app.css'),
            PackageAssetContribution::css('demo-module', PackageScope::Module, 'assets/packages/demo-module/module.css'),
            PackageAssetContribution::tailwindSource('demo-module', PackageScope::Module, 'packages/demo-module/templates'),
        ], PackageAssetRegistryBuilder::BUCKET_EXTENSION);

        self::assertStringContainsString('@source "../../../packages/demo-module/templates";', $registry);
        self::assertStringContainsString('@import "../../packages/demo-module/module.css";', $registry);
        self::assertStringNotContainsString('frontend-theme/app.css', $registry);
    }

    public function testItBuildsJavaScriptRegistryForThemePackages(): void
    {
        $builder = new PackageAssetRegistryBuilder();

        $registry = $builder->buildJavaScriptRegistry([
            PackageAssetContribution::javaScript('demo-module', PackageScope::Module, 'assets/packages/demo-module/module.js'),
            PackageAssetContribution::javaScript('site-theme', PackageScope::FrontendTheme, 'assets/packages/site-theme/theme.js'),
        ], PackageAssetRegistryBuilder::BUCKET_FRONTEND_THEME);

        self::assertStringContainsString('import "../../packages/site-theme/theme.js";', $registry);
        self::assertStringNotContainsString('demo-module/module.js', $registry);
    }

    public function testItRejectsUnsafeAssetPaths(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not traverse parent directories');

        PackageAssetContribution::css('demo-module', PackageScope::Module, '../outside.css');
    }

    public function testItAcceptsStaticAssetContributionsWithoutRegistryOutput(): void
    {
        $builder = new PackageAssetRegistryBuilder();
        $contribution = PackageAssetContribution::staticAsset(
            'demo-module',
            PackageScope::Module,
            'assets/packages/demo-module/images/logo.svg',
        );

        self::assertSame(PackageAssetContribution::TYPE_STATIC_ASSET, $contribution->type());
        self::assertStringNotContainsString('logo.svg', $builder->buildCssRegistry([
            $contribution,
        ], PackageAssetRegistryBuilder::BUCKET_EXTENSION));
        self::assertStringNotContainsString('logo.svg', $builder->buildJavaScriptRegistry([
            $contribution,
        ], PackageAssetRegistryBuilder::BUCKET_EXTENSION));
    }
}
