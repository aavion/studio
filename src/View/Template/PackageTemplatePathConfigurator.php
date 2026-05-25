<?php

declare(strict_types=1);

namespace App\View\Template;

use App\Core\Package\ActivePackageAssetProviderInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class PackageTemplatePathConfigurator implements EventSubscriberInterface
{
    private bool $configured = false;

    public function __construct(
        private readonly Environment $twig,
        private readonly ActivePackageAssetProviderInterface $packageProvider,
        private readonly PackageTemplatePathResolver $pathResolver,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 512],
        ];
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
            $packages = $this->packageProvider->packages();
        } catch (Throwable) {
            $packages = [];
        }

        foreach (TemplateNamespace::cases() as $namespace) {
            $paths = array_values(array_filter(
                $this->pathResolver->pathsForNamespace($namespace, $packages),
                static fn (string $path): bool => is_dir($path),
            ));

            if ([] !== $paths) {
                $loader->setPaths($paths, $namespace->value);
            }
        }

        $providerPaths = array_values(array_filter(
            $this->pathResolver->providerPaths($packages),
            static fn (string $path): bool => is_dir($path),
        ));

        if ([] !== $providerPaths) {
            $loader->setPaths($providerPaths, 'provider');
        }
    }
}
