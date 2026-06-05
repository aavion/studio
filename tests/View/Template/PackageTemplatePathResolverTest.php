<?php

declare(strict_types=1);

namespace App\Tests\View\Template;

use App\Core\Package\PackageScope;
use App\Entity\ExtensionPackage;
use App\View\Template\PackageTemplatePathResolver;
use App\View\Template\TemplateNamespace;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class PackageTemplatePathResolverTest extends TestCase
{
    public function testItOrdersFrontendOverridesBeforeNativeFallback(): void
    {
        $resolver = new PackageTemplatePathResolver('/project');
        $paths = $resolver->pathsForNamespace(TemplateNamespace::Frontend, [
            $this->package('blog', [PackageScope::Module]),
            $this->package('captcha', [PackageScope::CaptchaProvider]),
            $this->package('theme', [PackageScope::FrontendTheme]),
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
            $this->package('module', [PackageScope::Module]),
            $this->package('editor', [PackageScope::EditorProvider]),
            $this->package('theme', [PackageScope::BackendTheme]),
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
            $this->package('plain', [PackageScope::Module]),
            $this->package('system', [PackageScope::SystemTemplate]),
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
            $this->package('module', [PackageScope::Module]),
            $this->package('turnstile', [PackageScope::CaptchaProvider]),
            $this->package('tinymce', [PackageScope::EditorProvider]),
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

    /**
     * @param list<PackageScope> $scopes
     */
    private function package(string $name, array $scopes): ExtensionPackage
    {
        return new ExtensionPackage(
            $this->uuid(),
            $scopes,
            $name,
            'packages/'.$name,
        );
    }

    private function uuid(): string
    {
        return Uuid::v7()->toRfc4122();
    }
}
