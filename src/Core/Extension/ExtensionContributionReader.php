<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Filesystem\PathGuard;
use App\Entity\Extension;

final readonly class ExtensionContributionReader
{
    public function __construct(
        private string $projectDir,
        private PathGuard $pathGuard = new PathGuard(),
    ) {
    }

    public function read(Extension $extension): ExtensionRuntimeContributionRegistry
    {
        $registry = new ExtensionRuntimeContributionRegistry();
        $loaderPath = $this->loaderPath($extension);

        if (null === $loaderPath || !is_file($loaderPath)) {
            return $registry;
        }

        $result = (static function (string $loaderPath, Extension $extension): mixed {
            return require $loaderPath;
        })($loaderPath, $extension);

        $registry->add($extension, $this->activationContributions($extension, $result));

        return $registry;
    }

    /**
     * @return iterable<mixed>
     */
    private function activationContributions(Extension $extension, mixed $contribution): iterable
    {
        if (null === $contribution) {
            return;
        }

        if ($contribution instanceof ExtensionActivationContributionFactory) {
            yield from $this->activationContributions(
                $extension,
                $contribution->contributions(new ExtensionContributionContext($extension)),
            );

            return;
        }

        if ($contribution instanceof ExtensionRuntimeContributionFactory) {
            return;
        }

        if (is_iterable($contribution)) {
            foreach ($contribution as $item) {
                yield from $this->activationContributions($extension, $item);
            }

            return;
        }

        yield $contribution;
    }

    private function loaderPath(Extension $extension): ?string
    {
        try {
            return rtrim($this->projectDir, '/').'/'.$this->pathGuard->relativePath($extension->path().'/extension.php');
        } catch (\Throwable) {
            return null;
        }
    }
}
