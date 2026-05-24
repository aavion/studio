<?php

declare(strict_types=1);

namespace App\View\Template;

use App\Core\Filesystem\PathGuard;
use App\Core\Package\PackageAssetSyncPackage;
use App\Core\Package\PackageScope;

final readonly class PackageTemplatePathResolver
{
    public function __construct(
        private string $projectDir,
        private PathGuard $pathGuard = new PathGuard(),
    ) {
    }

    /**
     * @param iterable<PackageAssetSyncPackage> $packages
     *
     * @return list<string>
     */
    public function pathsForNamespace(string|TemplateNamespace $namespace, iterable $packages): array
    {
        $namespace = is_string($namespace) ? TemplateNamespace::fromName($namespace) : $namespace;
        $nativePath = $this->absolutePath($namespace->relativeDirectory());

        if (TemplateNamespace::Root !== $namespace) {
            return array_values(array_unique(array_merge(
                $this->packagePaths($packages, $namespace, $namespace->overrideScope()),
                [$nativePath],
            )));
        }

        $overridePaths = $this->packagePaths($packages, TemplateNamespace::Root, PackageScope::SystemTemplate);
        $fallbackOnlyPaths = $this->packagePaths($packages, TemplateNamespace::Root, null, PackageScope::SystemTemplate);

        return array_values(array_unique(array_merge($overridePaths, [$nativePath], $fallbackOnlyPaths)));
    }

    /**
     * @param iterable<PackageAssetSyncPackage> $packages
     *
     * @return list<string>
     */
    private function packagePaths(
        iterable $packages,
        TemplateNamespace $namespace,
        ?PackageScope $requiredScope,
        ?PackageScope $excludedScope = null,
    ): array {
        $paths = [];

        foreach ($packages as $package) {
            if (!$package instanceof PackageAssetSyncPackage) {
                continue;
            }

            if (null !== $requiredScope && !$package->hasScope($requiredScope)) {
                continue;
            }

            if (null !== $excludedScope && $package->hasScope($excludedScope)) {
                continue;
            }

            $paths[] = $this->absolutePath($package->directory().'/'.$namespace->packageRelativeDirectory());
        }

        return $paths;
    }

    private function absolutePath(string $relativePath): string
    {
        return rtrim($this->projectDir, '/').'/'.$this->pathGuard->relativePath($relativePath);
    }
}
