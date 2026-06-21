<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Extension\ExtensionAssetContribution;
use App\Core\Extension\ExtensionAssetRegistryBuilder;
use App\Core\Extension\ExtensionScope;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ExtensionAssetRegistryBuilderTest extends TestCase
{
    public function testItBuildsCssRegistryForExtensions(): void
    {
        $builder = new ExtensionAssetRegistryBuilder();

        $registry = $builder->buildCssRegistry([
            ExtensionAssetContribution::css('frontend-theme', ExtensionScope::FrontendTheme, 'assets/extensions/frontend-theme/app.css'),
            ExtensionAssetContribution::css('demo-module', ExtensionScope::Module, 'assets/extensions/demo-module/module.css'),
            ExtensionAssetContribution::tailwindSource('demo-module', ExtensionScope::Module, 'extensions/demo-module/templates'),
        ], ExtensionAssetRegistryBuilder::BUCKET_EXTENSION);

        self::assertStringContainsString('@source "../../../extensions/demo-module/templates";', $registry);
        self::assertStringContainsString('@import "../../extensions/demo-module/module.css";', $registry);
        self::assertStringNotContainsString('frontend-theme/app.css', $registry);
    }

    public function testItBuildsJavaScriptRegistryForThemeExtensions(): void
    {
        $builder = new ExtensionAssetRegistryBuilder();

        $registry = $builder->buildJavaScriptRegistry([
            ExtensionAssetContribution::javaScript('demo-module', ExtensionScope::Module, 'assets/extensions/demo-module/module.js'),
            ExtensionAssetContribution::javaScript('site-theme', ExtensionScope::FrontendTheme, 'assets/extensions/site-theme/theme.js'),
        ], ExtensionAssetRegistryBuilder::BUCKET_FRONTEND_THEME);

        self::assertStringContainsString('import "../../extensions/site-theme/theme.js";', $registry);
        self::assertStringNotContainsString('demo-module/module.js', $registry);
    }

    public function testItRejectsUnsafeAssetPaths(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('message.extension.asset.contribution_path_traversal');

        ExtensionAssetContribution::css('demo-module', ExtensionScope::Module, '../outside.css');
    }

    public function testItRejectsAssetContributionsWithoutExtensionIdentifier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('message.extension.asset.contribution_extension_invalid');

        ExtensionAssetContribution::css('', ExtensionScope::Module, 'assets/extensions/demo-module/module.css');
    }

    public function testItRejectsUnsupportedAssetContributionTypes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('message.extension.asset.contribution_type_invalid');

        new ExtensionAssetContribution('demo-module', ExtensionScope::Module, 'image', 'assets/extensions/demo-module/logo.svg');
    }

    public function testItAcceptsStaticAssetContributionsWithoutRegistryOutput(): void
    {
        $builder = new ExtensionAssetRegistryBuilder();
        $contribution = ExtensionAssetContribution::staticAsset(
            'demo-module',
            ExtensionScope::Module,
            'assets/extensions/demo-module/images/logo.svg',
        );

        self::assertSame(ExtensionAssetContribution::TYPE_STATIC_ASSET, $contribution->type());
        self::assertStringNotContainsString('logo.svg', $builder->buildCssRegistry([
            $contribution,
        ], ExtensionAssetRegistryBuilder::BUCKET_EXTENSION));
        self::assertStringNotContainsString('logo.svg', $builder->buildJavaScriptRegistry([
            $contribution,
        ], ExtensionAssetRegistryBuilder::BUCKET_EXTENSION));
    }

    public function testItBucketsApiScopedAssetsWithRegularExtensions(): void
    {
        $builder = new ExtensionAssetRegistryBuilder();

        self::assertSame(ExtensionAssetRegistryBuilder::BUCKET_EXTENSION, $builder->bucketForScope(ExtensionScope::Api));
        self::assertStringContainsString('@import "../../extensions/api-module/module.css";', $builder->buildCssRegistry([
            ExtensionAssetContribution::css('api-module', ExtensionScope::Api, 'assets/extensions/api-module/module.css'),
        ], ExtensionAssetRegistryBuilder::BUCKET_EXTENSION));
    }
}
