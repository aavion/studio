<?php

declare(strict_types=1);

namespace App\Tests\View\Template;

use App\Core\Extension\ActiveExtensionProviderInterface;
use App\Core\Extension\ExtensionScope;
use App\Entity\Extension;
use App\Tests\Support\FilesystemTestHelper;
use App\View\Template\ExtensionTemplatePathConfigurator;
use App\View\Template\ExtensionTemplatePathResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class ExtensionTemplatePathConfiguratorTest extends TestCase
{
    use FilesystemTestHelper;

    private string $root;

    protected function setUp(): void
    {
        $this->root = $this->createTemporaryDirectory('system-template-paths');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testItRegistersScopedExtensionPathsOnTwigFilesystemLoader(): void
    {
        $this->writeTestFile($this->root, 'templates/frontend/.keep', '');
        $this->writeTestFile($this->root, 'templates/backend/.keep', '');
        $this->writeTestFile($this->root, 'templates/.keep', '');
        $this->writeTestFile($this->root, 'extensions/theme/templates/frontend/.keep', '');
        $this->writeTestFile($this->root, 'extensions/backend/templates/backend/.keep', '');
        $this->writeTestFile($this->root, 'extensions/system/templates/.keep', '');
        $this->writeTestFile($this->root, 'extensions/module/templates/.keep', '');
        $this->writeTestFile($this->root, 'extensions/module/templates/frontend/.keep', '');
        $this->writeTestFile($this->root, 'extensions/module/templates/backend/.keep', '');
        $this->writeTestFile($this->root, 'extensions/captcha/templates/provider/.keep', '');
        $this->writeTestFile($this->root, 'extensions/editor/templates/provider/.keep', '');
        $this->writeTestFile($this->root, 'templates/provider/.keep', '');

        $loader = new FilesystemLoader();
        $twig = new Environment($loader);
        $configurator = new ExtensionTemplatePathConfigurator(
            $twig,
            new StaticExtensionProvider([
                $this->extension('theme', [ExtensionScope::FrontendTheme]),
                $this->extension('backend', [ExtensionScope::BackendTheme]),
                $this->extension('system', [ExtensionScope::SystemTemplate]),
                $this->extension('module', [ExtensionScope::Module]),
                $this->extension('captcha', [ExtensionScope::CaptchaProvider]),
                $this->extension('editor', [ExtensionScope::EditorProvider]),
            ]),
            new ExtensionTemplatePathResolver($this->root),
        );

        $configurator->configure();

        self::assertSame([
            $this->root.'/extensions/theme/templates/frontend',
            $this->root.'/templates/frontend',
            $this->root.'/extensions/module/templates/frontend',
        ], $loader->getPaths('frontend'));
        self::assertSame([
            $this->root.'/extensions/backend/templates/backend',
            $this->root.'/templates/backend',
            $this->root.'/extensions/module/templates/backend',
        ], $loader->getPaths('backend'));
        self::assertSame([
            $this->root.'/extensions/system/templates',
            $this->root.'/templates',
            $this->root.'/extensions/theme/templates',
            $this->root.'/extensions/backend/templates',
            $this->root.'/extensions/module/templates',
            $this->root.'/extensions/captcha/templates',
            $this->root.'/extensions/editor/templates',
        ], $loader->getPaths('root'));
        self::assertSame([
            $this->root.'/extensions/captcha/templates/provider',
            $this->root.'/extensions/editor/templates/provider',
            $this->root.'/templates/provider',
        ], $loader->getPaths('provider'));
    }

    public function testItLetsProviderNamespaceResolveActiveProviderBeforeNativeFallback(): void
    {
        $this->writeTestFile($this->root, 'extensions/captcha/templates/provider/captcha/field.html.twig', 'captcha provider');
        $this->writeTestFile($this->root, 'templates/provider/captcha/field.html.twig', 'captcha native');
        $this->writeTestFile($this->root, 'templates/provider/editor/richtext.html.twig', 'editor native');

        $loader = new FilesystemLoader();
        $twig = new Environment($loader);
        $configurator = new ExtensionTemplatePathConfigurator(
            $twig,
            new StaticExtensionProvider([
                $this->extension('captcha', [ExtensionScope::CaptchaProvider]),
            ]),
            new ExtensionTemplatePathResolver($this->root),
        );

        $configurator->configure();

        self::assertSame('captcha provider', $twig->render('@provider/captcha/field.html.twig'));
        self::assertSame('editor native', $twig->render('@provider/editor/richtext.html.twig'));
    }

    public function testItRegistersExtensionPathsBeforeIconConsoleCommands(): void
    {
        $this->writeTestFile($this->root, 'templates/.keep', '');
        $this->writeTestFile($this->root, 'extensions/module/templates/.keep', '');

        $loader = new FilesystemLoader();
        $twig = new Environment($loader);
        $configurator = new ExtensionTemplatePathConfigurator(
            $twig,
            new StaticExtensionProvider([
                $this->extension('module', [ExtensionScope::Module]),
            ]),
            new ExtensionTemplatePathResolver($this->root),
        );

        $configurator->onConsoleCommand($this->consoleEvent('ux:icons:lock'));

        self::assertContains($this->root.'/extensions/module/templates', $loader->getPaths('root'));
    }

    public function testItDoesNotRegisterExtensionPathsForUnrelatedConsoleCommands(): void
    {
        $this->writeTestFile($this->root, 'templates/.keep', '');
        $this->writeTestFile($this->root, 'extensions/module/templates/.keep', '');

        $loader = new FilesystemLoader();
        $twig = new Environment($loader);
        $configurator = new ExtensionTemplatePathConfigurator(
            $twig,
            new StaticExtensionProvider([
                $this->extension('module', [ExtensionScope::Module]),
            ]),
            new ExtensionTemplatePathResolver($this->root),
        );

        $configurator->onConsoleCommand($this->consoleEvent('cache:clear'));

        self::assertSame([], $loader->getPaths('root'));
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

    private function consoleEvent(string $commandName): ConsoleCommandEvent
    {
        return new ConsoleCommandEvent(new Command($commandName), new ArrayInput([]), new NullOutput());
    }
}

final readonly class StaticExtensionProvider implements ActiveExtensionProviderInterface
{
    /**
     * @param list<Extension> $extensions
     */
    public function __construct(private array $extensions)
    {
    }

    /**
     * @return list<Extension>
     */
    public function extensions(?ExtensionScope $scope = null): array
    {
        if (null === $scope) {
            return $this->extensions;
        }

        return array_values(array_filter(
            $this->extensions,
            static fn (Extension $extension): bool => $extension->hasScope($scope),
        ));
    }

    public function extension(string $extensionName): ?Extension
    {
        foreach ($this->extensions as $extension) {
            if ($extension->extensionName() === $extensionName) {
                return $extension;
            }
        }

        return null;
    }
}
