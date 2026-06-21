<?php

declare(strict_types=1);

namespace App\View\Template;

use App\Core\Extension\ActiveExtensionProviderInterface;
use App\Debug\SystemDebugCollector;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class ExtensionTemplatePathConfigurator implements EventSubscriberInterface
{
    private bool $configured = false;

    public function __construct(
        private readonly Environment $twig,
        private readonly ActiveExtensionProviderInterface $extensionProvider,
        private readonly ExtensionTemplatePathResolver $pathResolver,
        private readonly ?SystemDebugCollector $debugCollector = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => 'onConsoleCommand',
            KernelEvents::REQUEST => ['onKernelRequest', 512],
        ];
    }

    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $commandName = $event->getCommand()?->getName();
        if (!in_array($commandName, ['asset-map:compile', 'ux:icons:lock', 'ux:icons:warm-cache'], true)) {
            return;
        }

        $this->configure();
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->configure();
    }

    public function configure(): void
    {
        if ($this->configured) {
            return;
        }

        $this->configured = true;
        $loader = $this->twig->getLoader();

        if (!$loader instanceof FilesystemLoader) {
            return;
        }

        try {
            $extensions = $this->extensionProvider->extensions();
        } catch (Throwable) {
            $extensions = [];
        }

        $debugPaths = [];

        foreach (TemplateNamespace::cases() as $namespace) {
            $paths = array_values(array_filter(
                $this->pathResolver->pathsForNamespace($namespace, $extensions),
                static fn (string $path): bool => is_dir($path),
            ));
            $debugPaths[$namespace->value] = $paths;

            if ([] !== $paths) {
                $loader->setPaths($paths, $namespace->value);
            }
        }

        $providerPaths = array_values(array_filter(
            $this->pathResolver->providerPaths($extensions),
            static fn (string $path): bool => is_dir($path),
        ));

        if ([] !== $providerPaths) {
            $loader->setPaths($providerPaths, 'provider');
        }

        $debugPaths['provider'] = $providerPaths;
        $this->debugCollector?->recordTemplatePaths($debugPaths);
    }
}
