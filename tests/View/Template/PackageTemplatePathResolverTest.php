<?php

declare(strict_types=1);

namespace App\Tests\View\Template;

use App\Core\Package\PackageAssetSyncPackage;
use App\Core\Package\PackageScope;
use App\View\Template\PackageTemplatePathResolver;
use App\View\Template\TemplateNamespace;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PackageTemplatePathResolverTest extends TestCase
{
    public function testItOrdersFrontendOverridesBeforeNativeFallback(): void
    {
        $resolver = new PackageTemplatePathResolver('/project');
        $paths = $resolver->pathsForNamespace(TemplateNamespace::Frontend, [
            new PackageAssetSyncPackage('blog', 'packages/blog', [PackageScope::Module]),
            new PackageAssetSyncPackage('theme', 'packages/theme', [PackageScope::FrontendTheme]),
        ]);

        self::assertSame([
            '/project/packages/theme/templates/frontend',
            '/project/templates/frontend',
        ], $paths);
    }

    public function testItKeepsRootOverridesBehindSystemTemplateScope(): void
    {
        $resolver = new PackageTemplatePathResolver('/project');
        $paths = $resolver->pathsForNamespace('@root', [
            new PackageAssetSyncPackage('plain', 'packages/plain', [PackageScope::Module]),
            new PackageAssetSyncPackage('system', 'packages/system', [PackageScope::SystemTemplate]),
        ]);

        self::assertSame([
            '/project/packages/system/templates',
            '/project/templates',
            '/project/packages/plain/templates',
        ], $paths);
    }

    public function testItRejectsUnknownNamespaces(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported template namespace "unknown".');

        (new PackageTemplatePathResolver('/project'))->pathsForNamespace('unknown', []);
    }
}
