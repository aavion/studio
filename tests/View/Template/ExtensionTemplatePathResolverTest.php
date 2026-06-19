<?php

declare(strict_types=1);

namespace App\Tests\View\Template;

use App\Core\Extension\ExtensionScope;
use App\Entity\Extension;
use App\View\Template\ExtensionTemplatePathResolver;
use App\View\Template\TemplateNamespace;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ExtensionTemplatePathResolverTest extends TestCase
{
    public function testItOrdersFrontendOverridesBeforeNativeFallback(): void
    {
        $resolver = new ExtensionTemplatePathResolver('/project');
        $paths = $resolver->pathsForNamespace(TemplateNamespace::Frontend, [
            $this->extension('blog', [ExtensionScope::Module]),
            $this->extension('captcha', [ExtensionScope::CaptchaProvider]),
            $this->extension('theme', [ExtensionScope::FrontendTheme]),
        ]);

        self::assertSame([
            '/project/extensions/theme/templates/frontend',
            '/project/templates/frontend',
            '/project/extensions/blog/templates/frontend',
            '/project/extensions/captcha/templates/frontend',
        ], $paths);
    }

    public function testItOrdersBackendOverridesBeforeNativeFallback(): void
    {
        $resolver = new ExtensionTemplatePathResolver('/project');
        $paths = $resolver->pathsForNamespace(TemplateNamespace::Backend, [
            $this->extension('module', [ExtensionScope::Module]),
            $this->extension('editor', [ExtensionScope::EditorProvider]),
            $this->extension('theme', [ExtensionScope::BackendTheme]),
        ]);

        self::assertSame([
            '/project/extensions/theme/templates/backend',
            '/project/templates/backend',
            '/project/extensions/module/templates/backend',
            '/project/extensions/editor/templates/backend',
        ], $paths);
    }

    public function testItKeepsRootOverridesBehindSystemTemplateScope(): void
    {
        $resolver = new ExtensionTemplatePathResolver('/project');
        $paths = $resolver->pathsForNamespace('@root', [
            $this->extension('plain', [ExtensionScope::Module]),
            $this->extension('system', [ExtensionScope::SystemTemplate]),
        ]);

        self::assertSame([
            '/project/extensions/system/templates',
            '/project/templates',
            '/project/extensions/plain/templates',
        ], $paths);
    }

    public function testItBuildsProviderPathsBeforeNativeFallback(): void
    {
        $resolver = new ExtensionTemplatePathResolver('/project');
        $paths = $resolver->providerPaths([
            $this->extension('module', [ExtensionScope::Module]),
            $this->extension('turnstile', [ExtensionScope::CaptchaProvider]),
            $this->extension('tinymce', [ExtensionScope::EditorProvider]),
        ]);

        self::assertSame([
            '/project/extensions/turnstile/templates/provider',
            '/project/extensions/tinymce/templates/provider',
            '/project/templates/provider',
        ], $paths);
    }

    public function testItRejectsUnknownNamespaces(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('message.view.template_namespace.unsupported');

        (new ExtensionTemplatePathResolver('/project'))->pathsForNamespace('unknown', []);
    }

    /**
     * @param list<ExtensionScope> $scopes
     */
    private function extension(string $name, array $scopes): Extension
    {
        return new Extension(
            $this->uuid(),
            $scopes,
            $name,
            'extensions/'.$name,
        );
    }

    private function uuid(): string
    {
        return Uuid::v7()->toRfc4122();
    }
}
