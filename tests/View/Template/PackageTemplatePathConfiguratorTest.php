<?php

declare(strict_types=1);

namespace App\Tests\View\Template;

use App\Core\Package\ActivePackageProviderInterface;
use App\Core\Package\PackageScope;
use App\Entity\ExtensionPackage;
use App\Tests\Support\FilesystemTestHelper;
use App\View\Template\PackageTemplatePathConfigurator;
use App\View\Template\PackageTemplatePathResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class PackageTemplatePathConfiguratorTest extends TestCase
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

    public function testItRegistersScopedPackagePathsOnTwigFilesystemLoader(): void
    {
        $this->writeTestFile($this->root, 'templates/frontend/.keep', '');
        $this->writeTestFile($this->root, 'templates/backend/.keep', '');
        $this->writeTestFile($this->root, 'templates/.keep', '');
        $this->writeTestFile($this->root, 'packages/theme/templates/frontend/.keep', '');
        $this->writeTestFile($this->root, 'packages/backend/templates/backend/.keep', '');
        $this->writeTestFile($this->root, 'packages/system/templates/.keep', '');
        $this->writeTestFile($this->root, 'packages/module/templates/.keep', '');
        $this->writeTestFile($this->root, 'packages/module/templates/frontend/.keep', '');
        $this->writeTestFile($this->root, 'packages/module/templates/backend/.keep', '');
        $this->writeTestFile($this->root, 'packages/captcha/templates/provider/.keep', '');
        $this->writeTestFile($this->root, 'packages/editor/templates/provider/.keep', '');
        $this->writeTestFile($this->root, 'templates/provider/.keep', '');

        $loader = new FilesystemLoader();
        $twig = new Environment($loader);
        $configurator = new PackageTemplatePathConfigurator(
            $twig,
            new StaticPackageProvider([
                $this->package('theme', [PackageScope::FrontendTheme]),
                $this->package('backend', [PackageScope::BackendTheme]),
                $this->package('system', [PackageScope::SystemTemplate]),
                $this->package('module', [PackageScope::Module]),
                $this->package('captcha', [PackageScope::CaptchaProvider]),
                $this->package('editor', [PackageScope::EditorProvider]),
            ]),
            new PackageTemplatePathResolver($this->root),
        );

        $configurator->configure();

        self::assertSame([
            $this->root.'/packages/theme/templates/frontend',
            $this->root.'/templates/frontend',
            $this->root.'/packages/module/templates/frontend',
        ], $loader->getPaths('frontend'));
        self::assertSame([
            $this->root.'/packages/backend/templates/backend',
            $this->root.'/templates/backend',
            $this->root.'/packages/module/templates/backend',
        ], $loader->getPaths('backend'));
        self::assertSame([
            $this->root.'/packages/system/templates',
            $this->root.'/templates',
            $this->root.'/packages/theme/templates',
            $this->root.'/packages/backend/templates',
            $this->root.'/packages/module/templates',
            $this->root.'/packages/captcha/templates',
            $this->root.'/packages/editor/templates',
        ], $loader->getPaths('root'));
        self::assertSame([
            $this->root.'/packages/captcha/templates/provider',
            $this->root.'/packages/editor/templates/provider',
            $this->root.'/templates/provider',
        ], $loader->getPaths('provider'));
    }

    public function testItLetsProviderNamespaceResolveActiveProviderBeforeNativeFallback(): void
    {
        $this->writeTestFile($this->root, 'packages/captcha/templates/provider/captcha/field.html.twig', 'captcha provider');
        $this->writeTestFile($this->root, 'templates/provider/captcha/field.html.twig', 'captcha native');
        $this->writeTestFile($this->root, 'templates/provider/editor/richtext.html.twig', 'editor native');

        $loader = new FilesystemLoader();
        $twig = new Environment($loader);
        $configurator = new PackageTemplatePathConfigurator(
            $twig,
            new StaticPackageProvider([
                $this->package('captcha', [PackageScope::CaptchaProvider]),
            ]),
            new PackageTemplatePathResolver($this->root),
        );

        $configurator->configure();

        self::assertSame('captcha provider', $twig->render('@provider/captcha/field.html.twig'));
        self::assertSame('editor native', $twig->render('@provider/editor/richtext.html.twig'));
    }

    public function testItRegistersPackagePathsBeforeIconConsoleCommands(): void
    {
        $this->writeTestFile($this->root, 'templates/.keep', '');
        $this->writeTestFile($this->root, 'packages/module/templates/.keep', '');

        $loader = new FilesystemLoader();
        $twig = new Environment($loader);
        $configurator = new PackageTemplatePathConfigurator(
            $twig,
            new StaticPackageProvider([
                $this->package('module', [PackageScope::Module]),
            ]),
            new PackageTemplatePathResolver($this->root),
        );

        $configurator->onConsoleCommand($this->consoleEvent('ux:icons:lock'));

        self::assertContains($this->root.'/packages/module/templates', $loader->getPaths('root'));
    }

    public function testItDoesNotRegisterPackagePathsForUnrelatedConsoleCommands(): void
    {
        $this->writeTestFile($this->root, 'templates/.keep', '');
        $this->writeTestFile($this->root, 'packages/module/templates/.keep', '');

        $loader = new FilesystemLoader();
        $twig = new Environment($loader);
        $configurator = new PackageTemplatePathConfigurator(
            $twig,
            new StaticPackageProvider([
                $this->package('module', [PackageScope::Module]),
            ]),
            new PackageTemplatePathResolver($this->root),
        );

        $configurator->onConsoleCommand($this->consoleEvent('cache:clear'));

        self::assertSame([], $loader->getPaths('root'));
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

    private function consoleEvent(string $commandName): ConsoleCommandEvent
    {
        return new ConsoleCommandEvent(new Command($commandName), new ArrayInput([]), new NullOutput());
    }
}

final readonly class StaticPackageProvider implements ActivePackageProviderInterface
{
    /**
     * @param list<ExtensionPackage> $packages
     */
    public function __construct(private array $packages)
    {
    }

    /**
     * @return list<ExtensionPackage>
     */
    public function packages(?PackageScope $scope = null): array
    {
        if (null === $scope) {
            return $this->packages;
        }

        return array_values(array_filter(
            $this->packages,
            static fn (ExtensionPackage $package): bool => $package->hasScope($scope),
        ));
    }

    public function package(string $packageName): ?ExtensionPackage
    {
        foreach ($this->packages as $package) {
            if ($package->packageName() === $packageName) {
                return $package;
            }
        }

        return null;
    }
}
