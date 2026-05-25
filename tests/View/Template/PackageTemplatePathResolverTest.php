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
            new PackageAssetSyncPackage('captcha', 'packages/captcha', [PackageScope::CaptchaProvider]),
            new PackageAssetSyncPackage('theme', 'packages/theme', [PackageScope::FrontendTheme]),
        ]);

        self::assertSame([
            '/project/packages/theme/templates/frontend',
            '/project/templates/frontend',
            '/project/packages/blog/templates/frontend',
            '/project/packages/captcha/templates/frontend',
        ], $paths);
    }

    public function testItOrdersBackendOverridesBeforeNativeFallback(): void
    {
        $resolver = new PackageTemplatePathResolver('/project');
        $paths = $resolver->pathsForNamespace(TemplateNamespace::Backend, [
            new PackageAssetSyncPackage('module', 'packages/module', [PackageScope::Module]),
            new PackageAssetSyncPackage('editor', 'packages/editor', [PackageScope::EditorProvider]),
            new PackageAssetSyncPackage('theme', 'packages/theme', [PackageScope::BackendTheme]),
        ]);

        self::assertSame([
            '/project/packages/theme/templates/backend',
            '/project/templates/backend',
            '/project/packages/module/templates/backend',
            '/project/packages/editor/templates/backend',
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

    public function testItBuildsProviderPathsBeforeNativeFallback(): void
    {
        $resolver = new PackageTemplatePathResolver('/project');
        $paths = $resolver->providerPaths([
            new PackageAssetSyncPackage('module', 'packages/module', [PackageScope::Module]),
            new PackageAssetSyncPackage('turnstile', 'packages/turnstile', [PackageScope::CaptchaProvider]),
            new PackageAssetSyncPackage('tinymce', 'packages/tinymce', [PackageScope::EditorProvider]),
        ]);

        self::assertSame([
            '/project/packages/turnstile/templates/provider',
            '/project/packages/tinymce/templates/provider',
            '/project/templates/provider',
        ], $paths);
    }

    public function testItRejectsUnknownNamespaces(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported template namespace "unknown".');

        (new PackageTemplatePathResolver('/project'))->pathsForNamespace('unknown', []);
    }
}
