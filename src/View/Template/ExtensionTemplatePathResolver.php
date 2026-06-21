<?php

declare(strict_types=1);

namespace App\View\Template;

use App\Core\Filesystem\PathGuard;
use App\Core\Extension\ExtensionScope;
use App\Entity\Extension;

final readonly class ExtensionTemplatePathResolver
{
    public function __construct(
        private string $projectDir,
        private PathGuard $pathGuard = new PathGuard(),
    ) {
    }

    /**
     * @param iterable<Extension> $extensions
     *
     * @return list<string>
     */
    public function pathsForNamespace(string|TemplateNamespace $namespace, iterable $extensions): array
    {
        $namespace = is_string($namespace) ? TemplateNamespace::fromName($namespace) : $namespace;
        $nativePath = $this->absolutePath($namespace->relativeDirectory());

        $overrideScope = $namespace->overrideScope();
        $overridePaths = $this->extensionPaths($extensions, $namespace, $overrideScope);
        $fallbackOnlyPaths = $this->extensionPaths($extensions, $namespace, null, $overrideScope);

        return array_values(array_unique(array_merge($overridePaths, [$nativePath], $fallbackOnlyPaths)));
    }

    /**
     * @param iterable<Extension> $extensions
     *
     * @return list<string>
     */
    public function providerPaths(iterable $extensions): array
    {
        $paths = [];

        foreach ($extensions as $extension) {
            if (!$extension instanceof Extension || !$this->hasProviderScope($extension)) {
                continue;
            }

            $paths[] = $this->absolutePath($extension->path().'/templates/provider');
        }

        $paths[] = $this->absolutePath('templates/provider');

        return array_values(array_unique($paths));
    }

    /**
     * @param iterable<Extension> $extensions
     *
     * @return list<string>
     */
    private function extensionPaths(
        iterable $extensions,
        TemplateNamespace $namespace,
        ?ExtensionScope $requiredScope,
        ?ExtensionScope $excludedScope = null,
    ): array {
        $paths = [];

        foreach ($extensions as $extension) {
            if (!$extension instanceof Extension) {
                continue;
            }

            if (null !== $requiredScope && !$extension->hasScope($requiredScope)) {
                continue;
            }

            if (null !== $excludedScope && $extension->hasScope($excludedScope)) {
                continue;
            }

            $paths[] = $this->absolutePath($extension->path().'/'.$namespace->extensionRelativeDirectory());
        }

        return $paths;
    }

    private function hasProviderScope(Extension $extension): bool
    {
        foreach ($extension->scopes() as $scope) {
            if (str_ends_with($scope->value, '-provider')) {
                return true;
            }
        }

        return false;
    }

    private function absolutePath(string $relativePath): string
    {
        return rtrim($this->projectDir, '/').'/'.$this->pathGuard->relativePath($relativePath);
    }
}
